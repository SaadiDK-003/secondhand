export interface Message {
    id: number;
    body: string;
    mine: boolean;
    created_at: string;
}
export interface InboxStatus {
    unread_count: number;
    latest: {
        id: number;
        conversation_id: number;
        sender_name: string;
    } | null;
}
export interface Page<T> {
    data: T[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}
