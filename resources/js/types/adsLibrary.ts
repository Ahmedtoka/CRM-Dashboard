import type { MaterialStatus, WinnerTier } from '@/types/ads';

/** One card of the winners library (WinningMaterials::top): a material whose linked ads win over the last 30 days. */
export interface WinningMaterial {
    id: number;
    title: string;
    product_title: string | null;
    thumbnail: string | null;
    status: MaterialStatus;
    performance: {
        spend_tax: number | null;
        purchase_value: number | null;
        roas: number | null;
        real_orders: number;
        winner_tier: WinnerTier | null;
    };
    ads: { id: number; name: string; platform: string }[];
    /** The viewer may start a launch from it (author or operator). */
    can_reuse: boolean;
}
