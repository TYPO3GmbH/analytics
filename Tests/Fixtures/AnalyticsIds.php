<?php

declare(strict_types=1);

namespace T3G\Analytics\Tests\Fixtures;

/**
 * IDs in the format the API returns.
 * The middleware creates websiteId and instanceId as UUIDv4. The data API returns the externalWebsiteId as UUIDv1.
 */
final class AnalyticsIds
{
    public const WEBSITE_ID = '0f8fad5b-d9cb-469f-a165-70867728950e';
    public const INSTANCE_ID = '7c9e6679-7425-40de-944b-e07fc1f90ae7';
    public const EXTERNAL_WEBSITE_ID = '8e50a3e4-c8a0-11f1-9968-b3851ed159d6';
    public const API_KEY_ID = '6ba7b810-9dad-41d1-80b4-00c04fd430c8';
}
