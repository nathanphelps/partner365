<?php

namespace App\Enums;

enum AccessPackageResourceType: string
{
    case Group = 'group';
    case SharePointSite = 'sharepoint_site';
}
