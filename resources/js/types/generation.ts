export type GenerationStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

export type Generation = {
    id: string;
    retry_of_id: string | null;
    provider_id: string | null;
    status: GenerationStatus;
    status_label: string;
    is_final: boolean;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    estimated_min: string;
    estimated_max: string;
    charged: string | null;
    charged_exact: string | null;
    markup_percent: string;
    usage: {
        input_text_tokens: number;
        input_image_tokens: number;
        output_tokens: number;
        reasoning_tokens: number;
    } | null;
    image_url: string | null;
    image_expired: boolean;
    error_code: string | null;
    error_message: string | null;
    created_at: string;
    completed_at: string | null;
};

export type Option = {
    value: string;
    label: string;
};

export type CostEstimate = {
    rates: Record<string, string>;
    markup_percent: string;
    tokens: {
        input_text: string;
        input_image: string;
        output: string;
        reasoning: string;
    };
    min_price_nano: number;
    max_price_nano: number;
    min_price: string;
    max_price: string;
};

export type Credits = {
    balance: string;
    balance_nano: number;
    packs: number[];
    is_admin: boolean;
};

export type Paginated<T> = {
    data: T[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        last_page: number;
        total: number;
    };
};
