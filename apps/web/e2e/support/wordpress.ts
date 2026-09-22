import { request, type APIRequestContext } from "@playwright/test";

import { people } from "./people";

/*
 * The whole URL each time, not a Playwright baseURL. A request path that
 * starts with "/" replaces the base's path, so a base of ".../wp-json" plus
 * "/jwt-auth/v1/token" silently becomes a request to the site root — which
 * answers with the front page in HTML and a confusing parse error.
 */
const API = process.env.WORDPRESS_API_URL ?? "http://localhost:8080/wp-json";

/**
 * Arranges fixtures through the same API the app uses.
 *
 * A test that needs someone enrolled says so here rather than depending on
 * whatever the last test left behind, or on a developer not having clicked
 * Remove an hour ago. It also means the arranging itself is covered: if
 * /enrollments breaks, the tests that lean on it fail loudly at setup.
 */
class WordPress {
  private constructor(
    private readonly api: APIRequestContext,
    private readonly token: string
  ) {}

  static async asAdministrator(): Promise<WordPress> {
    const api = await request.newContext();

    const response = await api.post(`${API}/jwt-auth/v1/token`, {
      data: {
        username: people.administrator.username,
        password: people.administrator.password,
      },
    });

    if (!response.ok()) {
      throw new Error(
        `Could not sign in to WordPress as ${people.administrator.username}: ` +
          `${response.status()} ${await response.text()}`
      );
    }

    const { token } = (await response.json()) as { token: string };

    return new WordPress(api, token);
  }

  private get headers() {
    return { Authorization: `Bearer ${this.token}` };
  }

  /** The first published programme, which is what the demo data provides. */
  async firstProgramme(): Promise<{ id: number; title: string }> {
    const response = await this.api.get(`${API}/platform-cle/v1/authoring/programs`, {
      headers: this.headers,
    });

    const { programs } = (await response.json()) as {
      programs: { id: number; title: string; status: string }[];
    };

    const published = programs.find(
      (programme) => programme.status === "publish"
    );

    if (!published) {
      throw new Error("No published programme to test against.");
    }

    return { id: published.id, title: published.title };
  }

  async enrol(programmeId: number, email: string): Promise<void> {
    const response = await this.api.post(`${API}/platform-cle/v1/enrollments`, {
      headers: this.headers,
      data: { program_id: programmeId, emails: email },
    });

    if (!response.ok()) {
      throw new Error(`Could not enrol ${email}: ${await response.text()}`);
    }
  }

  async remove(programmeId: number, email: string): Promise<void> {
    const id = await this.userId(email);

    if (id === null) {
      return;
    }

    await this.api.delete(
      `${API}/platform-cle/v1/enrollments?program_id=${programmeId}&user_id=${id}`,
      { headers: this.headers }
    );
  }

  /** Who holds this address, or null. */
  async userId(email: string): Promise<number | null> {
    const response = await this.api.get(
      `${API}/wp/v2/users?search=${encodeURIComponent(email)}&context=edit`,
      { headers: this.headers }
    );

    if (!response.ok()) {
      return null;
    }

    const users = (await response.json()) as { id: number; email?: string }[];
    const match = users.find((user) => user.email === email) ?? users[0];

    return match ? match.id : null;
  }

  /** Puts someone back the way a test found them. */
  async setRole(email: string, role: string): Promise<void> {
    const id = await this.userId(email);

    if (id === null) {
      return;
    }

    await this.api.patch(`${API}/platform-cle/v1/people/${id}`, {
      headers: this.headers,
      data: { role },
    });
  }

  /** The address an account was created with. */
  async emailOf(username: string): Promise<string> {
    const response = await this.api.get(
      `${API}/wp/v2/users?search=${encodeURIComponent(username)}&context=edit`,
      { headers: this.headers }
    );

    const users = (await response.json()) as {
      slug: string;
      email: string;
      username?: string;
    }[];

    const match =
      users.find((user) => user.username === username) ??
      users.find((user) => user.slug === username.replace(/\./g, "-")) ??
      users[0];

    if (!match?.email) {
      throw new Error(`No email on file for ${username}.`);
    }

    return match.email;
  }
}

export { WordPress };
