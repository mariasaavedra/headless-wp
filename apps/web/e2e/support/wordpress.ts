import { request, type APIRequestContext } from "@playwright/test";

/** A node of the curriculum tree, as much of it as these tests read. */
type TreeNode = {
  id: number;
  title: string;
  type: string;
  children?: TreeNode[];
};

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

  /**
   * A programme's curriculum, as the builder sees it.
   *
   * Tests ask this rather than naming post ids: the ids differ between a
   * developer's database and the one CI builds from scratch, and a test that
   * hard-codes 612 is a test that passes in exactly one place.
   */
  async tree(programmeId: number): Promise<TreeNode> {
    const response = await this.api.get(
      `${API}/platform-cle/v1/authoring/programs/${programmeId}/tree`,
      { headers: this.headers }
    );

    return (await response.json()) as TreeNode;
  }

  /** Every node of a type, anywhere in the programme. */
  private async nodesOfType(
    programmeId: number,
    type: string
  ): Promise<TreeNode[]> {
    const found: TreeNode[] = [];

    const walk = (node: TreeNode) => {
      if (node.type === type) {
        found.push(node);
      }
      (node.children ?? []).forEach(walk);
    };

    walk(await this.tree(programmeId));

    return found;
  }

  /**
   * A module a participant can complete without passing anything first.
   *
   * A module whose quiz is required cannot be marked complete until that quiz
   * is passed, which is a different test than "can somebody mark a module
   * complete".
   */
  async completableModule(programmeId: number): Promise<TreeNode> {
    const modules = await this.nodesOfType(programmeId, "pcle_module");

    const free = modules.find(
      (module) =>
        !(module.children ?? []).some((child) => child.type === "pcle_quiz")
    );

    if (!free) {
      throw new Error("Every module in this programme is gated by a quiz.");
    }

    return free;
  }

  async firstQuiz(programmeId: number): Promise<TreeNode> {
    const [quiz] = await this.nodesOfType(programmeId, "pcle_quiz");

    if (!quiz) {
      throw new Error("No quiz in this programme to sit.");
    }

    return quiz;
  }

  /** Finds something the test made, so the test can unmake it. */
  async findByTitle(
    programmeId: number,
    title: string
  ): Promise<TreeNode | null> {
    const found: TreeNode[] = [];

    const walk = (node: TreeNode) => {
      if (node.title === title) {
        found.push(node);
      }
      (node.children ?? []).forEach(walk);
    };

    walk(await this.tree(programmeId));

    return found[0] ?? null;
  }

  /** Creates an item the way the builder does: as a draft. */
  async createNode(
    type: string,
    title: string,
    parentId = 0
  ): Promise<{ id: number; title: string }> {
    const response = await this.api.post(`${API}/platform-cle/v1/authoring/nodes`, {
      headers: this.headers,
      data: { type, title, parent_id: parentId },
    });

    if (!response.ok()) {
      throw new Error(`Could not create ${type}: ${await response.text()}`);
    }

    return (await response.json()) as { id: number; title: string };
  }

  /**
   * A throwaway account with a known password, for tests that change one.
   * The demo accounts are shared by every other test and must not move.
   */
  async createUser(
    username: string,
    password: string
  ): Promise<{ id: number; username: string; password: string }> {
    const response = await this.api.post(`${API}/wp/v2/users`, {
      headers: this.headers,
      data: {
        username,
        password,
        email: `${username}@example.test`,
        roles: ["pcle_student"],
      },
    });

    if (!response.ok()) {
      throw new Error(`Could not create ${username}: ${await response.text()}`);
    }

    const { id } = (await response.json()) as { id: number };
    return { id, username, password };
  }

  async deleteUser(id: number): Promise<void> {
    await this.api.delete(`${API}/wp/v2/users/${id}?force=true&reassign=1`, {
      headers: this.headers,
    });
  }

  /** Changes fields on a node, as the builder's PATCH does. */
  async updateNode(id: number, changes: Record<string, unknown>): Promise<void> {
    const response = await this.api.patch(
      `${API}/platform-cle/v1/authoring/nodes/${id}`,
      { headers: this.headers, data: changes }
    );

    if (!response.ok()) {
      throw new Error(`Could not update ${id}: ${await response.text()}`);
    }
  }

  /** `cascade` takes everything under it too — needed for a whole programme. */
  async deleteNode(id: number, cascade = false): Promise<void> {
    await this.api.delete(
      `${API}/platform-cle/v1/authoring/nodes/${id}${cascade ? "?cascade=true" : ""}`,
      { headers: this.headers }
    );
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
export type { TreeNode };
