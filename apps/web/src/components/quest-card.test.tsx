import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { Heatmap } from "@/components/heatmap";
import { QuestCard } from "@/components/quest-card";
import type { HeatmapDay, UserQuest } from "@/types/api";

function days(count: number, active = 0): HeatmapDay[] {
  return Array.from({ length: count }, (_, index) => ({
    date: `2026-08-${String((index % 28) + 1).padStart(2, "0")}`,
    count: index < active ? index + 1 : 0,
  }));
}

describe("Heatmap", () => {
  it("renders twelve weeks of cells with an accessible summary", () => {
    render(<Heatmap days={days(84, 3)} />);

    expect(screen.getByRole("img", { name: /activity heatmap/i })).toBeInTheDocument();
    expect(screen.getByText(/3 active days/)).toBeInTheDocument();
    // 84 cells + 5 legend swatches.
    expect(document.querySelectorAll('[title*="2026-08"]')).toHaveLength(84);
  });

  it("sums the event count", () => {
    render(<Heatmap days={days(84, 2)} />); // counts 1 + 2 = 3 events

    expect(screen.getByText(/· 3 events/)).toBeInTheDocument();
  });

  it("renders nothing without data", () => {
    const { container } = render(<Heatmap days={[]} />);

    expect(container).toBeEmptyDOMElement();
  });
});

function quest(overrides: Partial<UserQuest> = {}): UserQuest {
  return {
    id: 1,
    key: "daily_commit_3",
    title: "Triple Strike",
    description: "Land 3 commits today.",
    period: "daily",
    metric: "commits",
    icon: "🗡️",
    progress: 2,
    target: 3,
    xp_reward: 30,
    status: "active",
    period_start: "2026-10-27",
    period_end: "2026-10-27",
    completed_at: null,
    ...overrides,
  };
}

describe("QuestCard", () => {
  it("renders title, reward and progress", () => {
    render(<QuestCard quest={quest()} />);

    expect(screen.getByText("Triple Strike")).toBeInTheDocument();
    expect(screen.getByText("+30 XP")).toBeInTheDocument();
    expect(screen.getByText("2 / 3")).toBeInTheDocument();

    const bar = screen.getByRole("progressbar", { name: "Triple Strike progress" });
    expect(bar).toHaveAttribute("aria-valuenow", "67");
  });

  it("shows the completed state without a claim button", () => {
    render(<QuestCard quest={quest({ status: "completed", progress: 3, completed_at: "2026-10-27T10:00:00Z" })} />);

    expect(screen.getByText("✓ Completed")).toBeInTheDocument();
    expect(screen.queryByRole("button")).not.toBeInTheDocument();
  });

  it("dims expired quests", () => {
    const { container } = render(<QuestCard quest={quest({ status: "expired" })} />);

    expect(screen.getByText("Expired")).toBeInTheDocument();
    expect(container.querySelector("article")).toHaveClass("opacity-60");
  });

  it("labels weekly bosses", () => {
    render(<QuestCard quest={quest({ period: "weekly", title: "Merge Mauler" })} />);

    expect(screen.getByText("👑 Weekly boss")).toBeInTheDocument();
  });
});
