import type { OrderRow, Paginated } from '@/types/admin';

/** The /orders tabs (fresh-orders F5). */
export type OrdersTab = 'list' | 'analytics' | 'ads';

export interface OrdersTotals {
    orders: number;
    real_orders: number;
    revenue: number;
    aov: number;
    customers: number;
    new_customers?: number;
    repeat_customers?: number;
    units: number;
}

export interface OrdersGroupRow {
    key: string | null;
    label: string | null;
    orders: number;
    revenue: number;
    /** Only on the «rest» row (`key === '_rest'`): how many groups it folds. */
    count?: number;
}

export interface OrdersProductRow {
    title: string;
    image_url: string | null;
    units: number;
    revenue: number;
}

export interface OrdersAnalytics {
    totals: OrdersTotals;
    governorates: OrdersGroupRow[];
    districts: OrdersGroupRow[];
    frequency: {
        one: number;
        two: number;
        three_plus: number;
        customers: { id: number; name: string | null; phone: string | null; orders: number }[];
    };
    products: OrdersProductRow[];
    statuses: { key: string; orders: number }[];
    days: { date: string; orders: number; revenue: number }[];
}

export interface OrdersByAdRow {
    ad_id: number | null;
    ad: string | null;
    thumbnail_url: string | null;
    external_id: string | null;
    /** Ad account platform, or `direct` for orders without an ad. */
    platform: string | null;
    campaign_id: number | null;
    campaign: string | null;
    ad_set_id: number | null;
    ad_set: string | null;
    orders: number;
    revenue: number;
    units: number;
}

export interface AdOrdersAd {
    id: number;
    name: string | null;
    thumbnail_url: string | null;
    platform: string | null;
    external_id: string | null;
    campaign: string | null;
    ad_set: string | null;
    manager_url: string | null;
}

export interface AdOrdersPage {
    ad: AdOrdersAd;
    summary: OrdersTotals;
    products: OrdersProductRow[];
    orders: Paginated<OrderRow>;
}
