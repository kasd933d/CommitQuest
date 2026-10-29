import { beforeEach, describe, expect, it, vi } from "vitest";
import { GET } from "@/app/api/badge/[username]/route";

const apiMock = vi.fn();

vi.mock("@/lib/api", () => ({
  apiServer: (path: string) => apiMock(path),
}));

/** Route handler params: the `[username]` segment (may carry a `.svg` suffix). */
function segment(username: string): { params: Promise<{ username: string }> } {
  return { params: Promise.resolve({ username }) };
}

describe("README badge route", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("renders level and streak for a public profile", async () => {
    apiMock.mockResolvedValue({
      username: "octo",
      level: 12,
      current_streak: 9,
    });

    const response = await GET(new Request("http://localhost:3000/api/badge/octo.svg"), segment("octo"));

    expect(response.status).toBe(200);
    expect(response.headers.get("Content-Type")).toBe("image/svg+xml; charset=utf-8");
    // PRD §4.8: "CommitQuest · Lv 12 · 🔥 9 day streak".
    expect(await response.text()).toContain("CommitQuest · Lv 12 · 🔥 9 day streak");
  });

  it("says 'day streak' for a one-day streak", async () => {
    apiMock.mockResolvedValue({ username: "solo", level: 2, current_streak: 1 });

    const response = await GET(new Request("http://localhost:3000/api/badge/solo.svg"), segment("solo"));

    expect(await response.text()).toContain("🔥 1 day streak");
  });

  it("falls back to a neutral badge for unknown or private profiles", async () => {
    apiMock.mockRejectedValue(new Error("404"));

    const response = await GET(
      new Request("http://localhost:3000/api/badge/ghost.svg"),
      segment("ghost"),
    );

    expect(response.status).toBe(200);
    expect(await response.text()).toContain("CommitQuest · @ghost");
  });

  it("caches for ten minutes (PRD §4.8)", async () => {
    apiMock.mockResolvedValue({ username: "cached", level: 3, current_streak: 0 });

    const response = await GET(
      new Request("http://localhost:3000/api/badge/cached.svg"),
      segment("cached"),
    );

    expect(response.headers.get("Cache-Control")).toContain("max-age=600");
  });

  it("rejects malformed usernames without calling the API", async () => {
    const response = await GET(
      new Request("http://localhost:3000/api/badge/..%2Fsecrets.svg"),
      segment("..%2Fsecrets"),
    );

    expect(apiMock).not.toHaveBeenCalled();
    expect(await response.text()).toContain("CommitQuest · @unknown");
  });

  it("strips the .svg extension from the path segment", async () => {
    apiMock.mockResolvedValue({ username: "with-ext", level: 5, current_streak: 2 });

    await GET(new Request("http://localhost:3000/api/badge/with-ext.svg"), segment("with-ext"));

    expect(apiMock).toHaveBeenCalledWith("/users/with-ext");
  });
});
