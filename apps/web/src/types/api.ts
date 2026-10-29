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

/** One cell of the 12-week streak heatmap (`GET /me/heatmap`). */
export interface HeatmapDay {
  date: string; // YYYY-MM-DD in the user's timezone
  count: number;
}

export interface HeatmapResponse {
  days: HeatmapDay[];
}

export type QuestPeriod = "daily" | "weekly";

export type QuestStatus = "active" | "completed" | "expired";

/** One assigned quest (`GET /me/quests`). */
export interface UserQuest {
  id: number;
  key: string;
  title: string;
  description: string;
  period: QuestPeriod;
  metric: string;
  icon: string;
  progress: number;
  target: number;
  xp_reward: number;
  status: QuestStatus;
  period_start: string;
  period_end: string;
  completed_at: string | null;
}

export interface QuestsResponse {
  current: UserQuest[];
  recent: UserQuest[];
}

export type BadgeRarity = "common" | "rare" | "epic" | "legendary";

/** One badge with the viewer's unlock state (`GET /me/badges`). */
export interface Badge {
  key: string;
  name: string;
  description: string;
  icon: string;
  rarity: BadgeRarity;
  unlocked: boolean;
  unlocked_at: string | null;
}

export type NotificationType =
  | "level_up"
  | "quest_complete"
  | "badge_unlocked"
  | "streak_at_risk"
  | "guild_invite";

/** One in-app notification (`GET /me/notifications`). */
export interface AppNotification {
  id: number;
  type: NotificationType;
  title: string;
  body: string;
  data: Record<string, unknown> | null;
  read_at: string | null;
  created_at: string;
}

export interface NotificationsResponse {
  data: AppNotification[];
  unread_count: number;
}

/** `POST /me/notifications/read` response. */
export interface MarkNotificationsReadResponse {
  unread_count: number;
}

/** Public profile (`GET /users/{username}`). */
export interface PublicProfile {
  username: string;
  display_name: string | null;
  avatar_url: string | null;
  total_xp: number;
  level: number;
  current_streak: number;
  longest_streak: number;
  streak_freezes: number;
  joined_at: string | null;
  heatmap: HeatmapDay[];
  badges: Badge[];
  recent_quests: UserQuest[];
}

export type LeaderboardScope = "weekly" | "all";

export interface LeaderboardEntry {
  user_id: number;
  username: string;
  display_name: string | null;
  avatar_url: string | null;
  xp: number;
  level: number;
  rank: number;
}

export interface LeaderboardResponse {
  scope: LeaderboardScope;
  week_start: string | null;
  entries: LeaderboardEntry[];
}

export interface Guild {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  is_public: boolean;
  owner_id: number;
  owner_username?: string;
  member_count: number;
  is_member: boolean;
  /** Only present for members. */
  invite_code?: string;
  created_at: string | null;
}

export interface GuildLeaderboardEntry {
  user_id: number;
  username: string;
  display_name: string | null;
  avatar_url: string | null;
  weekly_xp: number;
  rank: number;
}

export interface GuildsResponse {
  mine: Guild[];
  discover: Guild[];
}

export interface GuildDetailResponse {
  guild: Guild;
  leaderboard: GuildLeaderboardEntry[];
}

/** `GET /stats` response (landing page counters). */
export interface PlatformStats {
  users: number;
  total_xp: number;
  commits: number;
  pull_requests: number;
  quests_completed: number;
  badges_unlocked: number;
  guilds: number;
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
