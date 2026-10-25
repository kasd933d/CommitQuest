import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { ActivityFeed } from "@/components/activity-feed";
import type { ActivityEvent } from "@/types/api";

const events: ActivityEvent[] = [
  {
    id: 3,
    type: "pr_merged",
    label: "Pull request merged",
    repo_full_name: "octocat/hello-world",
    message: "feat: add the thing",
    url: "https://github.com/octocat/hello-world/pull/7",
    occurred_at: "2026-10-20T10:00:00+00:00",
    xp_awarded: 40,
    awarded_at: "2026-10-20T10:00:05+00:00",
    skipped_reason: null,
  },
  {
    id: 2,
    type: "commit",
    label: "Commit",
    repo_full_name: "octocat/spoon-fork",
    message: "fix: spoon curvature",
    url: null,
    occurred_at: "2026-10-19T09:00:00+00:00",
    xp_awarded: 5,
    awarded_at: "2026-10-19T09:00:02+00:00",
    skipped_reason: null,
  },
  {
    id: 1,
    type: "commit",
    label: "Commit",
    repo_full_name: "octocat/spoon-fork",
    message: null,
    url: null,
    occurred_at: "2026-10-18T08:00:00+00:00",
    xp_awarded: 0,
    awarded_at: null,
    skipped_reason: "daily_repo_cap",
  },
];

describe("ActivityFeed", () => {
  it("renders one row per event with its XP", () => {
    render(<ActivityFeed events={events} />);

    const rows = screen.getAllByTestId("activity-item");
    expect(rows).toHaveLength(3);

    expect(screen.getByText("+40")).toBeTruthy();
    expect(screen.getByText("+5")).toBeTruthy();
    // Capped events show a dash instead of a reward.
    expect(screen.getByText("—")).toBeTruthy();
  });

  it("shows the repository and the human readable activity label", () => {
    render(<ActivityFeed events={events} />);

    expect(screen.getByText("Pull request merged")).toBeTruthy();
    expect(screen.getByText(/octocat\/hello-world/)).toBeTruthy();
    expect(screen.getByText("feat: add the thing")).toBeTruthy();
  });

  it("explains the empty state instead of showing a blank panel", () => {
    render(<ActivityFeed events={[]} />);

    expect(screen.getByTestId("activity-empty")).toBeTruthy();
    expect(screen.getByText(/synced automatically every 15 minutes/)).toBeTruthy();
    expect(screen.queryAllByTestId("activity-item")).toHaveLength(0);
  });
});
