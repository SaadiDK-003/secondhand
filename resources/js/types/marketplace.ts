export type AttributeValue = string | number | boolean | string[] | null;
export type ListingImage = {
    id: number;
    status: string;
    card_url: string | null;
    large_url: string | null;
    thumbnail_url: string | null;
    srcset: string | null;
};
export type ListingSummary = {
    id: number;
    slug: string;
    title: string;
    price: string;
    status: string;
    city: string;
    category: string;
    images: ListingImage[];
};
export type ListingDetail = ListingSummary & {
    description: string;
    condition: string;
    is_negotiable: boolean;
    category_id: number;
    province_id: number;
    city_id: number;
    area_id: number | null;
    province: string;
    area: string | null;
    published_at: string | null;
    attributes: Record<string, AttributeValue>;
    details: { name: string; value: string }[];
    seller: { name: string; joined: string; url: string };
};
export type CategoryAttribute = {
    id: number;
    name: string;
    slug: string;
    type: 'text' | 'number' | 'boolean' | 'select' | 'multi_select';
    is_required: boolean;
    options: { value: string; label: string }[];
};
export type Category = {
    id: number;
    parent_id: number | null;
    name: string;
    attributes: CategoryAttribute[];
};
export type Province = {
    id: number;
    name: string;
    cities: {
        id: number;
        name: string;
        areas: { id: number; name: string }[];
    }[];
};

export type PublicListing = {
    id: number;
    slug: string;
    title: string;
    price: string;
    city: string;
    area: string | null;
    category: string;
    published_at: string | null;
    image_url: string | null;
    image_srcset: string | null;
    is_favorite: boolean;
};
export type ListingResults = {
    data: PublicListing[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
export type SearchFilters = {
    q?: string;
    category_id?: number | string;
    province_id?: number | string;
    city_id?: number | string;
    area_id?: number | string;
    min_price?: string;
    max_price?: string;
    condition?: string;
    sort?: string;
    attributes?: Record<string, AttributeValue>;
};
