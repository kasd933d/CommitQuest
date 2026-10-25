/**
 * Response shapes of the Laravel API (apps/api), mirrored here so the frontend
 * is type-safe. Keep in sync with the API Resources.
 */

export type SyncStatus = "ok" | "error" | "needs_reauth";

export interface User {
  id: number;
  username: string;
  display_name: string | null;
  avatar_url: string | null;
  timezone: string;
  is_public: boolean;

  total_xp: number;
  level: number;
  level_progress: number;
  xp_at_level_start: number;
  xp_at_next_level: number;
  xp_to_next_level: number;

  current_streak: number;
  longest_streak: number;
  streak_freezes: number;
  last_active_date: string | null;

  sync_status: SyncStatus | null;
  last_synced_at: string | null;
  joined_at: string;
}

/** Envelope used by Laravel JSON resources. */
export interface Resource<T> {
  data: T;
}

export interface AuthResponse {
  token: string;
  user: User;
  first_login: boolean;
}

export interface ApiError {
  message: string;
  errors?: Record<string, string[]>;
}

export class ApiRequestError extends Error {
  readonly status: number;
  readonly errors?: Record<string, string[]>;

  constructor(status: number, message: string, errors?: Record<string, string[]>) {
    super(message);
    this.name = "ApiRequestError";
    this.status = status;
    this.errors = errors;
  }
}
