FROM mcr.microsoft.com/dotnet/sdk:9.0 AS build
WORKDIR /src
COPY Partner365.Bridge/Partner365.Bridge.csproj Partner365.Bridge/
RUN dotnet restore Partner365.Bridge/Partner365.Bridge.csproj
COPY Partner365.Bridge/ Partner365.Bridge/
RUN dotnet publish Partner365.Bridge/Partner365.Bridge.csproj -c Release -o /app --no-restore

FROM mcr.microsoft.com/dotnet/aspnet:9.0
# curl is required for the docker-compose healthcheck; the aspnet image ships without it.
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY --from=build /app .
EXPOSE 8080
ENTRYPOINT ["dotnet", "Partner365.Bridge.dll"]
