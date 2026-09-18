<?php

/*
| Public legal pages (/privacy, /terms, /data-deletion). Placeholders filled by
| LegalController: :company, :contact (contact email, or contact_fallback),
| :months (crm.legal.retention_months), :website.
|
| Section shape: heading, body (paragraphs), items (bullets — a string, or
| [bold label, text]), after (paragraphs after the list).
*/

return [
    'contact_fallback' => 'us in a message on our Facebook Page',

    'ui' => [
        'brand_tagline' => 'Customer support',
        'nav' => [
            'privacy' => 'Privacy policy',
            'terms' => 'Terms of use',
            'data_deletion' => 'Data deletion',
        ],
        'switch_language' => 'العربية',
        'switch_language_short' => 'ع',
        'switch_language_label' => 'اقرأ هذه الصفحة بالعربية',
        'last_updated' => 'Last updated: :date',
        'on_this_page' => 'On this page',
        'contact' => 'Contact',
        'contact_email' => 'Email',
        'contact_page' => 'Message our Facebook Page',
        'rights' => '© :year :company. All rights reserved.',
        'skip' => 'Skip to content',
        'back_to_top' => 'Back to top',
        'lookup' => [
            'heading' => 'Check the status of a deletion request',
            'body' => 'If you asked for deletion by removing our app from Facebook, Facebook gave you a confirmation code. Enter it here to see where your request is.',
            'label' => 'Confirmation code',
            'placeholder' => 'e.g. 7KQ2M9XR4TBW1ZNA',
            'submit' => 'Check status',
            'result_for' => 'Request :code',
            'requested_at' => 'Received',
            'completed_at' => 'Completed',
            'unknown' => 'We could not find a request with this code. Please check that you typed it exactly as shown, or contact us and we will look into it.',
            'status' => [
                'pending' => 'Received — deletion is in progress. This normally finishes within minutes and always within 30 days.',
                'completed' => 'Completed — the data linked to this request has been deleted from our support system.',
                'not_found' => 'Completed — we found no data linked to this Facebook account in our support system, so there was nothing to delete.',
            ],
            'badge' => [
                'pending' => 'In progress',
                'completed' => 'Deleted',
                'not_found' => 'Nothing to delete',
            ],
        ],
    ],

    'pages' => [
        'privacy' => [
            'title' => 'Privacy policy',
            'description' => 'How :company collects, uses and protects the information you share with us on Messenger and in comments on our Facebook Page.',
            'intro' => [
                'This policy explains what information :company collects when you contact us through our Facebook Page — in Messenger or in the comments on our posts — how we use it, who we share it with and the choices you have.',
                'It covers the customer-support system our team uses to read and answer your messages. We have tried to keep it short and in plain language.',
            ],
            'sections' => [
                [
                    'id' => 'who-we-are',
                    'heading' => 'Who we are',
                    'body' => [
                        ':company is a women\'s clothing store in Egypt (:website). We are responsible for the information described in this policy.',
                        'For any question about privacy, or to use any of your rights below, write to :contact.',
                    ],
                ],
                [
                    'id' => 'what-we-collect',
                    'heading' => 'What information we collect',
                    'items' => [
                        ['From Messenger', 'your name and profile picture as Facebook shares them with our Page; a page-scoped ID (a number Facebook gives our Page to identify your conversation with us — it is not your Facebook password and gives us no access to your account); the messages you send us and our replies; photos and files you send (for example a photo of an item); and the date and time of each message.'],
                        ['From comments on our posts', 'the text of your comment, your name and profile ID as shown to our Page, and our public or private reply.'],
                        ['Details you choose to share', 'such as your phone number, delivery address, city, order number, size, and the details of a return, exchange or complaint.'],
                        ['From our online store', 'if you have ordered from :website (our store runs on Shopify), we link your conversation to your customer and order records: name, phone, email, delivery address, items, amounts, and payment and delivery status.'],
                        ['Support records', 'the cases our team opens for you (for example a return or a complaint), their status, and internal notes our staff add to help each other.'],
                    ],
                    'after' => [
                        'We do not ask for or collect passwords, bank card numbers, or your friends list, and we do not use Facebook Login for customers.',
                    ],
                ],
                [
                    'id' => 'how-we-use',
                    'heading' => 'Why we use it',
                    'items' => [
                        'To answer your questions about products, sizes, prices and availability.',
                        'To take, confirm and follow up on your orders and their delivery.',
                        'To handle returns, exchanges and complaints.',
                        'To keep our service consistent — for example, reviewing questions our assistant could not answer so we can answer them better next time.',
                        'To prevent spam, fraud and abuse, and to meet our legal obligations.',
                    ],
                    'after' => [
                        'We do not use your messages for advertising and we never sell your information. We handle personal data in line with applicable law, including Egypt\'s Personal Data Protection Law (Law No. 151 of 2020).',
                    ],
                ],
                [
                    'id' => 'automated-replies',
                    'heading' => 'Automated replies (AI)',
                    'body' => [
                        'Some of our replies are written by an automated assistant so we can answer quickly, at any hour. To write a reply, our AI provider processes the content of your message together with the context it needs (for example the product you asked about or your order status).',
                        'Our AI provider is Anthropic. It processes this content on our behalf only to generate the reply; under its commercial terms it does not use this content to train its models.',
                        'Decisions about returns, refunds and complaints are made by our team, not by the assistant. You can ask to talk to a member of our team at any time and the conversation will be passed to them.',
                    ],
                ],
                [
                    'id' => 'sharing',
                    'heading' => 'Who we share it with',
                    'body' => [
                        'We share information only with the service providers that help us run our support service, and only what each one needs:',
                    ],
                    'items' => [
                        ['Meta (Facebook and Messenger)', 'delivers messages and comments between you and our Page.'],
                        ['Anthropic', 'our AI provider, which generates automated replies.'],
                        ['Shopify', 'the platform our online store runs on, which holds order and customer records.'],
                        ['Our hosting provider', 'the cloud servers (Cloudways) where our support system and its data are stored.'],
                        ['Delivery companies', 'receive the name, phone number and address needed to deliver an order you placed.'],
                    ],
                    'after' => [
                        'Some of these providers process data outside Egypt. We choose providers that protect data with appropriate safeguards. We may also disclose information when the law requires it. We do not sell or rent your information to anyone.',
                    ],
                ],
                [
                    'id' => 'retention',
                    'heading' => 'How long we keep it',
                    'body' => [
                        'We keep conversations, comments and support cases for :months months after the last message, then we delete or anonymise them.',
                        'Order records in our store may be kept longer where accounting or tax law requires it. If you ask us to delete your data, we do so sooner — see "How to delete your data" below.',
                    ],
                ],
                [
                    'id' => 'security',
                    'heading' => 'How we protect it',
                    'body' => [
                        'Our support system is only reachable over encrypted connections (HTTPS). Only our customer-service staff can see conversations, each with their own password-protected account and permissions limited to their role. Messages from Facebook are accepted only when they carry Facebook\'s verified signature.',
                        'No system is perfectly secure, but we work to protect your information and will act quickly if something goes wrong.',
                    ],
                ],
                [
                    'id' => 'your-rights',
                    'heading' => 'Your rights',
                    'body' => ['You can ask us to:'],
                    'items' => [
                        ['Access', 'tell you what information we hold about you and give you a copy.'],
                        ['Correct', 'fix information that is wrong or out of date, such as your phone number or address.'],
                        ['Delete', 'erase your information from our support system.'],
                        ['Talk to a person', 'stop automated replies in your conversation and have our team answer you instead.'],
                    ],
                    'after' => [
                        'Write to :contact. We answer within 30 days. To protect you, we may ask you to write from the same Facebook account so we know the request is really yours.',
                    ],
                ],
                [
                    'id' => 'deletion',
                    'heading' => 'How to delete your data',
                    'body' => [
                        'Send "delete my data" to our Facebook Page, write to :contact, or remove our app from your Facebook settings (Settings & privacy → Settings → Apps and websites). The data deletion page explains each option, what is deleted and how to check the status of your request.',
                    ],
                    'link' => 'data-deletion',
                ],
                [
                    'id' => 'children',
                    'heading' => 'Children',
                    'body' => [
                        'Our support service is not meant for children under 13, and we do not knowingly collect their information. If you believe a child has sent us personal information, contact us and we will delete it.',
                    ],
                ],
                [
                    'id' => 'changes',
                    'heading' => 'Changes to this policy',
                    'body' => [
                        'We may update this policy when our service or the law changes. The date at the top shows the latest version. If a change is significant, we will also announce it on our Facebook Page.',
                    ],
                ],
                [
                    'id' => 'contact',
                    'heading' => 'Contact us',
                    'body' => [
                        'Questions or requests about your information: write to :contact.',
                    ],
                ],
            ],
        ],

        'terms' => [
            'title' => 'Terms of use',
            'description' => 'The terms for using :company\'s customer-support channel on Messenger and Facebook comments.',
            'intro' => [
                'These terms apply when you contact :company through Messenger or in the comments on our Facebook Page (our "support channel"). By messaging us you agree to them. They are short on purpose.',
            ],
            'sections' => [
                [
                    'id' => 'purpose',
                    'heading' => 'What the support channel is for',
                    'body' => [
                        'You can use it to ask about our products, sizes, prices and availability, to place or follow up on an order, and to request a return, an exchange or make a complaint.',
                    ],
                ],
                [
                    'id' => 'automated',
                    'heading' => 'Automated replies',
                    'body' => [
                        'Some replies are written by an automated assistant that uses AI. We work hard to make them accurate, but they can make mistakes. The price, total and delivery details in your order confirmation are the ones that count. You can ask to talk to a member of our team at any time.',
                    ],
                ],
                [
                    'id' => 'orders',
                    'heading' => 'Orders, prices and returns',
                    'body' => [
                        'Prices and availability shared in chat are for guidance and can change until your order is confirmed. Orders, payment, delivery, exchanges and returns follow the store policies published on :website.',
                    ],
                ],
                [
                    'id' => 'acceptable-use',
                    'heading' => 'Using the channel respectfully',
                    'body' => ['Please do not use the support channel to:'],
                    'items' => [
                        'send abusive, threatening or offensive messages or comments;',
                        'send spam, advertising or misleading content;',
                        'share other people\'s personal information without their permission;',
                        'try to break, overload or misuse our systems.',
                    ],
                    'after' => [
                        'We may hide comments or stop replying to accounts that do.',
                    ],
                ],
                [
                    'id' => 'your-content',
                    'heading' => 'What you send us',
                    'body' => [
                        'You are responsible for the messages and photos you send. You allow us to use them only to handle your request — for example, to check a photo of an item you want to return.',
                    ],
                ],
                [
                    'id' => 'availability',
                    'heading' => 'Availability',
                    'body' => [
                        'We try to answer quickly, but we cannot promise an immediate reply at every hour, and the channel may occasionally be unavailable, for example if Facebook or Messenger is down.',
                    ],
                ],
                [
                    'id' => 'liability',
                    'heading' => 'Our responsibility',
                    'body' => [
                        'We provide the support channel as it is. To the extent the law allows, :company is not responsible for losses caused by service interruptions, by Facebook, or by relying on information in chat that differs from your confirmed order. Nothing in these terms limits your rights as a consumer under Egyptian law.',
                    ],
                ],
                [
                    'id' => 'privacy',
                    'heading' => 'Privacy',
                    'body' => [
                        'Our privacy policy explains what information we collect through the support channel and how we use it.',
                    ],
                    'link' => 'privacy',
                ],
                [
                    'id' => 'law',
                    'heading' => 'Law and changes',
                    'body' => [
                        'These terms are governed by the laws of the Arab Republic of Egypt. We may update them; the date at the top shows the latest version.',
                    ],
                ],
                [
                    'id' => 'contact',
                    'heading' => 'Contact us',
                    'body' => [
                        'Questions about these terms: write to :contact.',
                    ],
                ],
            ],
        ],

        'data_deletion' => [
            'title' => 'Data deletion',
            'description' => 'How to ask :company to delete the personal data we hold about you, what gets deleted and how to check the status of your request.',
            'intro' => [
                'You can ask us to delete the personal data our support system holds about you at any time, for free. This page explains how, what is deleted and how long it takes.',
            ],
            'methods_heading' => 'How to request deletion',
            'methods' => [
                'page' => [
                    'title' => 'Message our Facebook Page',
                    'body' => 'Send "delete my data" (or "امسح بياناتي") to our Page on Messenger. Our team will confirm your request in the same conversation and delete your data. Please send it from the Facebook account you used to contact us.',
                ],
                'email' => [
                    'title' => 'Email us',
                    'body' => 'Write to :contact with the name on your Facebook account and, if you have one, your order number, so we can find your conversations.',
                ],
                'facebook' => [
                    'title' => 'Remove our app from Facebook',
                    'body' => 'On Facebook, go to Settings & privacy → Settings → Apps and websites. If our app is listed there, choose Remove and ask for your data to be deleted. Facebook sends us the request automatically and gives you a confirmation code and a link to this page to check its status.',
                ],
            ],
            'sections' => [
                [
                    'id' => 'what-is-deleted',
                    'heading' => 'What we delete',
                    'items' => [
                        'your customer profile in our support system: name, profile picture and page-scoped ID;',
                        'all your conversations and messages, and the photos and files you sent;',
                        'the copies of your comments and of our replies stored in our system;',
                        'support cases (returns, exchanges, complaints) and our internal notes about them;',
                        'phone numbers, addresses and order numbers you shared in chat, and the copy of your orders in our support system.',
                    ],
                ],
                [
                    'id' => 'what-may-remain',
                    'heading' => 'What may remain',
                    'items' => [
                        'Records the law requires us to keep, such as invoices for orders you placed, stay in our store account only for as long as the law requires.',
                        'Comments you posted publicly stay on Facebook until you delete them there — we delete our copy.',
                        'Copies in our backups are removed as the backups are routinely replaced.',
                    ],
                ],
                [
                    'id' => 'timeline',
                    'heading' => 'How long it takes',
                    'body' => [
                        'We complete every deletion request within 30 days. Requests that come from Facebook are usually completed within minutes. When the deletion is done, the data cannot be recovered.',
                    ],
                ],
                [
                    'id' => 'questions',
                    'heading' => 'Questions',
                    'body' => [
                        'If something is unclear, write to :contact.',
                    ],
                ],
            ],
        ],
    ],
];
