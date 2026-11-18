/**
 * Shapes sent by GenerationController / GenerationHistoryQuery / GenerationEstimateController.
 * Money arrives as nanodollar strings plus server-formatted text; the browser never does money math.
 */
export type GenerationStatus =
    | 'pending'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

export type GenerationHistoryItem = {
    id: string;
    prompt: string;
    size: string;
    quality: string;
    reference_image_count: number;
    status: GenerationStatus;
    in_flight: boolean;
    estimate: { min_nanos: string; max_nanos: string; display: string };
    charge: { nanos: string; display: string } | null;
    failure: { code: string; message: string; provisional: boolean } | null;
    /** Our stored copy (permanent, signed link) once copied; Pixelforge's 24-hour link before that. */
    image: {
        url: string;
        expires_at: string | null;
        expired: boolean;
        stored: boolean;
    } | null;
    created_at: string;
    finished_at: string | null;
};

export type CursorPage<T> = {
    data: T[];
    next_cursor: string | null;
    next_page_url: string | null;
    prev_cursor: string | null;
    prev_page_url: string | null;
    per_page: number;
};

export type CostEstimate = {
    count: number;
    min_nanos: string;
    max_nanos: string;
    min_display: string;
    max_display: string;
    /** Prompt lengths (characters) this same price holds for. */
    same_price_for_characters: { from: number; to: number };
};

export type GenerationDetail = GenerationHistoryItem & {
    reference_image_urls: string[];
};
