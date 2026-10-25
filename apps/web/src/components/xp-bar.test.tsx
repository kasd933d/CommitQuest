import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { XpBar } from "@/components/xp-bar";

describe("XpBar", () => {
  it("renders level, XP and progress", () => {
    render(<XpBar level={3} progress={40} totalXp={520} xpToNextLevel={380} />);

    expect(screen.getByText("LV 3")).toBeInTheDocument();
    expect(screen.getByText("520 XP")).toBeInTheDocument();
    expect(screen.getByText("380 XP to level 4")).toBeInTheDocument();

    const bar = screen.getByRole("progressbar");
    expect(bar).toHaveAttribute("aria-valuenow", "40");
    expect(bar).toHaveAttribute("aria-label", "Level 3 progress");
  });

  it("clamps progress outside 0–100", () => {
    render(<XpBar level={1} progress={150} totalXp={0} xpToNextLevel={100} />);

    expect(screen.getByRole("progressbar")).toHaveAttribute("aria-valuenow", "100");
  });
});
