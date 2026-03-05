#!/usr/bin/env bash
set -euo pipefail

# Creates an Entra ID (Azure AD) app registration for Partner365
# and writes the credentials to the .env file.

APP_NAME="Partner365"
ENV_FILE="$(dirname "$0")/../.env"

# --- Azure CLI detection and login ---

if ! command -v az &>/dev/null; then
    echo "Azure CLI not found. Installing..."
    if command -v apt-get &>/dev/null; then
        curl -sL https://aka.ms/InstallAzureCLIDeb | sudo bash
    elif command -v brew &>/dev/null; then
        brew install azure-cli
    elif command -v dnf &>/dev/null; then
        sudo rpm --import https://packages.microsoft.com/keys/microsoft.asc
        sudo dnf install -y azure-cli
    else
        echo "Error: Could not auto-install Azure CLI. Install manually: https://aka.ms/install-azure-cli"
        exit 1
    fi
fi

if ! az account show &>/dev/null 2>&1; then
    echo "Not logged in to Azure CLI. Launching login..."
    az login --allow-no-subscriptions
fi

TENANT_ID=$(az account show --query tenantId -o tsv)
echo "Using tenant: $TENANT_ID"

# --- App registration ---

GRAPH_API="00000003-0000-0000-c000-000000000000"

# Required application permissions (appRoleIds from Microsoft Graph)
declare -A PERMISSIONS=(
    ["User.Read.All"]="df021288-bdef-4463-88db-98f22de89214"
    ["User.ReadWrite.All"]="741f803b-c850-494e-b5df-cde7c675a1ca"
    ["User.Invite.All"]="09850681-111b-4a89-9bed-3f2cae46d706"
    ["Policy.Read.All"]="246dd0d5-5bd0-4def-940b-0421030a5b68"
    ["Policy.ReadWrite.CrossTenantAccess"]="338163d7-f101-4c92-94ba-ca46fe52447c"
    ["Policy.ReadWrite.Authorization"]="edd3c878-b384-41fd-85c2-07c811cde6f8"
    ["CrossTenantInformation.ReadBasic.All"]="cac88765-0581-4025-9725-5ebc13f729ee"
)

RESOURCE_ACCESS_ITEMS=""
for perm_name in "${!PERMISSIONS[@]}"; do
    perm_id="${PERMISSIONS[$perm_name]}"
    [ -n "$RESOURCE_ACCESS_ITEMS" ] && RESOURCE_ACCESS_ITEMS+=","
    RESOURCE_ACCESS_ITEMS+="{\"id\":\"$perm_id\",\"type\":\"Role\"}"
done

REQUIRED_ACCESS="[{\"resourceAppId\":\"$GRAPH_API\",\"resourceAccess\":[$RESOURCE_ACCESS_ITEMS]}]"

echo "Creating app registration: $APP_NAME"
APP_ID=$(az ad app create \
    --display-name "$APP_NAME" \
    --sign-in-audience "AzureADMyOrg" \
    --required-resource-accesses "$REQUIRED_ACCESS" \
    --query appId -o tsv)

echo "App registered: $APP_ID"

echo "Creating client secret (2 year expiry)..."
SECRET=$(az ad app credential reset \
    --id "$APP_ID" \
    --append \
    --years 2 \
    --query password -o tsv)

echo "Creating service principal..."
az ad sp create --id "$APP_ID" -o none 2>/dev/null || true

# --- Update .env ---

if [ ! -f "$ENV_FILE" ]; then
    echo ".env not found — copying from .env.example"
    cp "$(dirname "$0")/../.env.example" "$ENV_FILE"
fi

update_env() {
    local key="$1" value="$2"
    if grep -q "^${key}=" "$ENV_FILE"; then
        sed -i "s|^${key}=.*|${key}=${value}|" "$ENV_FILE"
    else
        echo "${key}=${value}" >> "$ENV_FILE"
    fi
}

update_env "MICROSOFT_GRAPH_TENANT_ID" "$TENANT_ID"
update_env "MICROSOFT_GRAPH_CLIENT_ID" "$APP_ID"
update_env "MICROSOFT_GRAPH_CLIENT_SECRET" "$SECRET"

echo ""
echo "============================================"
echo "  App Registration Complete"
echo "============================================"
echo "  Tenant ID:     $TENANT_ID"
echo "  Client ID:     $APP_ID"
echo "  Client Secret:  (written to .env)"
echo "============================================"
echo ""
echo ".env updated with Graph credentials."
echo ""
echo "IMPORTANT: Grant admin consent for the application permissions:"
echo ""
echo "  az ad app permission admin-consent --id $APP_ID"
echo ""
echo "Or use the Azure Portal:"
echo "  https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationMenuBlade/~/CallAnAPI/appId/$APP_ID"
