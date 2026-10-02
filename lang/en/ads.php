<?php

return [
    'assignment_before_open' => 'The start date is before the current assignment starts.',
    'assignment_overlaps' => 'The start date overlaps an earlier assignment.',
    'unassigned' => 'Unassigned',
    'recommendation' => [
        'winner' => 'Raise the budget 20–30% gradually',
        'promising' => 'Let it gather 3 more days of data',
        'loser' => 'Stop it or change the creative',
        'neutral' => 'Keep watching it',
    ],
    'flash' => [
        'connected' => 'Connected. :count ad accounts found; the last 90 days are loading in the background.',
        'saved' => 'Saved.',
        'test_ok' => 'Connection works.',
        'sync_queued' => 'Sync started.',
        'deleted' => 'Deleted.',
        'assigned' => 'Assignment saved.',
        'buyer_archived' => 'The buyer has account history, so it was archived instead of deleted.',
    ],
    'credentials' => [
        'access_token' => 'Access token',
        'advertiser_ids' => 'Advertiser IDs (comma separated)',
        'developer_token' => 'Developer token',
        'client_id' => 'Client ID',
        'client_secret' => 'Client secret',
        'refresh_token' => 'Refresh token',
        'login_customer_id' => 'Manager account ID (optional)',
    ],
    'materials' => [
        'file_type' => 'The file :name is not supported. Allowed: images (JPG, PNG, WebP, GIF) and video (MP4, MOV, WebM).',
        'file_too_big' => 'The file :name is larger than the allowed :mb MB.',
        'file_upload_failed' => 'The file could not be uploaded. Try again.',
        'bad_link' => 'This link is not valid. It must start with http or https.',
        'status' => ['not_started' => 'Not started', 'activated' => 'Activated', 'done' => 'Done'],
        'stock' => ['in' => 'In stock', 'out' => 'Out of stock', 'none' => 'No product'],
        'csv' => [
            'title' => 'Title', 'created' => 'Created', 'product' => 'Product', 'collections' => 'Collections', 'types' => 'Types',
            'status' => 'Status', 'stock' => 'Stock', 'drive_links' => 'Drive links', 'ads' => 'Linked ads', 'spend' => 'Spend', 'roas' => 'ROAS',
        ],
        'stock_csv' => [
            'title' => 'Material', 'product' => 'Product', 'variants' => 'Variations', 'price' => 'Price', 'quantity' => 'Quantity', 'collections' => 'Collections', 'availability' => 'Available', 'yes' => 'Yes', 'no' => 'No',
        ],
    ],
];
