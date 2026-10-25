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

/** Internal activity types produced by the normalizer (PRD §4.2). */
export type ActivityType =
  | "commit"
  | "pr_opened"
  | "pr_merged"
  | "pr_review"
  | "issue_closed"
  | "new_repo";

export interface ActivityEvent {
  id: number;
  type: ActivityType;
  label: string;
  repo_full_name: string;
  message: string | null;
  url: string | null;
  occurred_at: string;
  xp_awarded: number;
  awarded_at: string | null;
  skipped_reason: string | null;
}

export interface SyncState {
  /** `cooldown` while a manual sync is throttled (5 minutes). */
  state: "idle" | "cooldown";
  last_synced_at: string | null;
}

/** `GET /me/activity` response: page of events plus the current sync state. */
export interface ActivityFeed extends Resource<ActivityEvent[]> {
  sync: SyncState;
}

/** `POST /me/sync` response. */
export interface SyncResponse {
  status: "queued" | "throttled";
  queued_at?: string;
  cooldown_seconds?: number;
  retry_after?: number;
  message?: string;
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
