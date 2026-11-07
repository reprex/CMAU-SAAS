<?php

/*
 * The CrowdMerch store menus (Scott, 7 Nov 2026; docs/menus.md). `shopify:menus --apply` creates
 * or replaces each menu by handle on the store .env points at. The theme reads them by handle
 * (cm-main, cm-popular, cm-foot-*), so the store's other menus are never touched.
 *
 * Each item is [title, target] or [title, target, children]. Targets:
 *   page:<handle>        a store page (linked by id, so a renamed handle keeps working)
 *   collection:<handle>  a store collection
 *   pick:<key>           the first collection found for the key in `picks` below, else its fallback
 *   url:<path>           a plain link
 *   a|b                  the first of these that is on the store
 */

$categories = [
    // The category landing pages (live: /pages/custom-branded-…), else a collection of that name.
    ['Clothing', 'page:custom-branded-clothing|collection:clothing'],
    ['Headwear', 'page:custom-branded-headwear|collection:headwear'],
    ['Bags', 'page:custom-branded-bags|collection:bags'],
    ['Drinkware', 'page:custom-branded-drinkware|collection:drinkware'],
    ['Tech', 'page:custom-branded-tech-merch|collection:tech'],
    ['Office & stationery', 'page:custom-branded-office-stationery|collection:office'],
    ['Pens & pencils', 'page:custom-branded-pens-pencils'],
    ['Event merch', 'page:custom-branded-event-merch|collection:events'],
    ['Home & living', 'page:custom-branded-home-living-merch|collection:home-personal'],
    ['Health & personal', 'page:custom-branded-health-personal-merch'],
    ['Leisure & outdoors', 'page:custom-branded-leisure-outdoors-merch|collection:outdoors-golf'],
    ['Keyrings & tools', 'page:custom-branded-keyrings-tools'],
    ['Stress shapes & toys', 'page:custom-branded-stress-shapes-balls-toys'],
    ['Food & drink', 'page:custom-branded-food-drink-by-replicat|collection:confectionery'],
];

return [
    // Popular product collections: matched by the range's subcategory tag (shopify_collections),
    // then by collection title; otherwise the fallback link.
    'picks' => [
        'tshirts' => ['tags' => ['tshirts', 'tees'], 'titles' => ['T-Shirts', 'T-shirts', 'Tees'], 'fallback' => 'page:custom-branded-clothing|collection:clothing'],
        'polos' => ['tags' => ['poloshirts', 'polos'], 'titles' => ['Polo Shirts', 'Polos'], 'fallback' => 'page:custom-branded-clothing|collection:clothing'],
        'hoodies' => ['tags' => ['hoodies', 'hoodiesjumpers', 'jumpershoodies', 'sweatshirts'], 'titles' => ['Hoodies', 'Hoodies & Jumpers'], 'fallback' => 'page:custom-branded-clothing|collection:clothing'],
        'caps' => ['tags' => ['caps', 'baseballcaps', 'truckercaps'], 'titles' => ['Caps', 'Baseball Caps', 'Trucker Caps'], 'fallback' => 'page:custom-branded-headwear|collection:headwear'],
        'buckethats' => ['tags' => ['buckethats', 'hats', 'sunhats', 'buckethatssunhats'], 'titles' => ['Bucket Hats', 'Hats', 'Sun Hats'], 'fallback' => 'page:custom-branded-headwear|collection:headwear'],
        'totebags' => ['tags' => ['totebags', 'tote', 'totes'], 'titles' => ['Tote Bags', 'Totes'], 'fallback' => 'page:custom-branded-bags|collection:bags'],
        'stubbies' => ['tags' => ['stubbyholders', 'stubbycoolers', 'stubbies'], 'titles' => ['Stubby Holders', 'Stubby Coolers'], 'fallback' => 'page:custom-branded-drinkware|collection:drinkware'],
        'lanyards' => ['tags' => ['lanyards'], 'titles' => ['Lanyards'], 'fallback' => 'page:custom-branded-event-merch|collection:events'],
        'workwear' => ['tags' => ['workwear', 'tradework', 'uniforms', 'corporateuniforms'], 'titles' => ['Workwear', 'Trade Work', 'Uniforms'], 'fallback' => 'page:custom-branded-clothing|collection:clothing'],
    ],

    'menus' => [
        'cm-main' => ['title' => 'CrowdMerch main menu', 'items' => [
            ['Find products', 'page:find-products', $categories],
            ['Get started', 'page:start'],
            ['Case studies', 'url:/#cases', [
                ['The Redgum Years', 'page:the-redgum-years-corrugated-highway-tour-of-australia-2025-26'],
                ['The Rock Orchestra', 'page:the-rock-orchestra-2026-tour-of-australia-new-zealand'],
                ['ERMHA365', 'page:case-study-ermha365'],
                ['Love Your Brain', 'page:big-red-dog-studio-love-your-brain-concussion-awareness-campaign-2026'],
                ['Australian Poker League', 'page:australia-poker-league-apl'],
            ]],
            ['Services', 'url:/#services', [
                ['Custom & sourced products', 'page:product-sourcing-request'],
                ['Distro: storage & mail-outs', 'page:distro'],
                ['CrowdLink stores', 'page:crowdlink'],
                ['CD, vinyl & album production', 'page:replicat'],
            ]],
            ['Help', 'page:faq', [
                ['FAQ', 'page:faq'],
                ['Book a chat', 'page:book-a-chat'],
                ['Contact', 'page:contact'],
                ['About Replicat', 'page:about-replicat'],
            ]],
        ]],

        'cm-popular' => ['title' => 'CrowdMerch popular', 'items' => [
            ['T-shirts', 'pick:tshirts'],
            ['Polo shirts', 'pick:polos'],
            ['Hoodies', 'pick:hoodies'],
            ['Caps', 'pick:caps'],
            ['Bucket & sun hats', 'pick:buckethats'],
            ['Tote bags', 'pick:totebags'],
            ['Stubby holders', 'pick:stubbies'],
            ['Lanyards', 'pick:lanyards'],
            ['AS Colour', 'page:custom-branded-as-colour-range'],
        ]],

        'cm-foot-clothing' => ['title' => 'Footer: Branded clothing', 'items' => [
            ['Custom printed T-shirts', 'pick:tshirts'],
            ['Embroidered polo shirts', 'pick:polos'],
            ['Custom hoodies & jumpers', 'pick:hoodies'],
            ['Branded caps & hats', 'page:custom-branded-headwear|collection:headwear'],
            ['Workwear & uniforms', 'pick:workwear'],
            ['AS Colour range', 'page:custom-branded-as-colour-range'],
            ['All clothing', 'page:custom-branded-clothing|collection:clothing'],
        ]],

        'cm-foot-merch' => ['title' => 'Footer: Merch & gifts', 'items' => [
            ['Printed tote bags', 'pick:totebags'],
            ['Stubby holders', 'pick:stubbies'],
            ['Branded drinkware', 'page:custom-branded-drinkware|collection:drinkware'],
            ['Custom lanyards', 'pick:lanyards'],
            ['Tech & USB', 'page:custom-branded-tech-merch|collection:tech'],
            ['Event & conference merch', 'page:custom-branded-event-merch|collection:events'],
            ['All products', 'page:find-products'],
        ]],

        'cm-foot-crowdmerch' => ['title' => 'Footer: CrowdMerch', 'items' => [
            ['Get started', 'page:start'],
            ['Case studies', 'url:/#cases'],
            ['Custom & sourced products', 'page:product-sourcing-request'],
            ['Distro: storage & mail-outs', 'page:distro'],
            ['CrowdLink stores', 'page:crowdlink'],
            ['CD & vinyl production', 'page:replicat'],
            ['About Replicat', 'page:about-replicat'],
        ]],

        'cm-foot-help' => ['title' => 'Footer: Help & policies', 'items' => [
            ['FAQ', 'page:faq'],
            ['Samples', 'page:how-do-i-order-a-sample'],
            ['Order terms', 'url:/policies/terms-of-service'],
            ['Returns & warranties', 'page:exchanges-returns-refunds-warranties'],
            ['Book a chat', 'page:book-a-chat'],
        ]],
    ],
];
