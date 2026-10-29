import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { NotificationBell } from "@/components/notification-bell";
import type { NotificationsResponse } from "@/types/api";

const fetchMock = vi.fn();

vi.mock("@/lib/api-fetch", () => ({
  apiFetch: (path: string, init?: RequestInit) => fetchMock(path, init),
}));

function response(overrides: Partial<NotificationsResponse> = {}): NotificationsResponse {
  return {
    data: [
      {
        id: 1,
        type: "quest_complete",
        title: "Quest complete: Triple Strike",
        body: "You earned 30 XP.",
        data: null,
        read_at: null,
        created_at: new Date().toISOString(),
      },
    ],
    unread_count: 1,
    ...overrides,
  };
}

describe("NotificationBell", () => {
  beforeEach(() => {
    fetchMock.mockResolvedValue(response());
  });

  afterEach(() => {
    vi.clearAllMocks();
  });

  it("polls the notifications endpoint on mount", async () => {
    render(<NotificationBell />);

    await waitFor(() =>
      expect(fetchMock).toHaveBeenCalledWith("/me/notifications?per_page=10", undefined),
    );
  });

  it("shows the unread badge", async () => {
    render(<NotificationBell />);

    await waitFor(() =>
      expect(screen.getByRole("button", { name: /1 unread/i })).toBeInTheDocument(),
    );
  });

  it("opens the panel and marks notifications read", async () => {
    fetchMock.mockResolvedValueOnce(response());
    render(<NotificationBell />);

    await waitFor(() => expect(fetchMock).toHaveBeenCalled());

    fetchMock.mockResolvedValueOnce({ unread_count: 0 });
    fireEvent.click(screen.getByRole("button"));

    await waitFor(() =>
      expect(fetchMock).toHaveBeenCalledWith("/me/notifications/read", { method: "POST" }),
    );
    expect(await screen.findByText(/Quest complete: Triple Strike/)).toBeInTheDocument();

    // The badge disappears once everything is read.
    await waitFor(() =>
      expect(screen.getByRole("button", { name: /notifications/i })).toBeInTheDocument(),
    );
  });

  it("survives API failures without crashing", async () => {
    fetchMock.mockRejectedValue(new Error("offline"));
    render(<NotificationBell />);

    fireEvent.click(screen.getByRole("button"));

    await waitFor(() =>
      expect(screen.getByText("Could not load notifications.")).toBeInTheDocument(),
    );
  });
});
