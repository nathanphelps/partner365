<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sharepoint_sites', function (Blueprint $table) {
            // Sharing controls
            $table->string('sharing_domain_restriction_mode')->nullable()->after('external_sharing_capability');
            $table->text('sharing_allowed_domain_list')->nullable()->after('sharing_domain_restriction_mode');
            $table->text('sharing_blocked_domain_list')->nullable()->after('sharing_allowed_domain_list');
            $table->string('default_sharing_link_type')->nullable()->after('sharing_blocked_domain_list');
            $table->string('default_link_permission')->nullable()->after('default_sharing_link_type');

            // External user expiration
            $table->integer('external_user_expiration_days')->nullable()->after('default_link_permission');
            $table->boolean('override_tenant_expiration_policy')->default(false)->after('external_user_expiration_days');

            // Access restrictions (read-only caps)
            $table->string('conditional_access_policy')->nullable()->after('override_tenant_expiration_policy');
            $table->boolean('allow_editing')->default(true)->after('conditional_access_policy');
            $table->string('limited_access_file_type')->nullable()->after('allow_editing');
            $table->boolean('allow_downloading_non_web_viewable')->default(true)->after('limited_access_file_type');
        });
    }

    public function down(): void
    {
        Schema::table('sharepoint_sites', function (Blueprint $table) {
            $table->dropColumn([
                'sharing_domain_restriction_mode',
                'sharing_allowed_domain_list',
                'sharing_blocked_domain_list',
                'default_sharing_link_type',
                'default_link_permission',
                'external_user_expiration_days',
                'override_tenant_expiration_policy',
                'conditional_access_policy',
                'allow_editing',
                'limited_access_file_type',
                'allow_downloading_non_web_viewable',
            ]);
        });
    }
};
