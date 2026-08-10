import { getAuthToken, setAuthCookie, clearAuthCookie } from "@/lib/auth";

const WORDPRESS_API_URL = process.env.WORDPRESS_API_URL;

class WordPressAuthError extends Error {
  constructor(message = "Invalid username or password.") {
    super(message);
    this.name = "WordPressAuthError";
  }
}

class WordPressApiError extends Error {
  status: number;

  constructor(message: string, status: number) {
    super(message);
    this.name = "WordPressApiError";
    this.status = status;
  }
}

type WordPressFetchOptions = RequestInit & {
  auth?: boolean;
};

async function wordpressFetch(
  path: string,
  { auth = false, headers, ...options }: WordPressFetchOptions = {}
) {
  if (!WORDPRESS_API_URL) {
    throw new Error("WORDPRESS_API_URL is not configured.");
  }

  const requestHeaders = new Headers(headers);

  if (auth) {
    const token = await getAuthToken();

    if (!token) {
      throw new WordPressAuthError("Not authenticated.");
    }

    requestHeaders.set("Authorization", `Bearer ${token}`);
  }

  const response = await fetch(`${WORDPRESS_API_URL}${path}`, {
    ...options,
    headers: requestHeaders,
    cache: options.cache ?? "no-store",
  });

  if (response.status === 401 || response.status === 403) {
    throw new WordPressApiError(
      "WordPress rejected the request as unauthorized.",
      response.status
    );
  }

  if (!response.ok) {
    throw new WordPressApiError(
      `WordPress request to ${path} failed.`,
      response.status
    );
  }

  let data: unknown;
  try {
    data = await response.json();
  } catch {
    throw new Error(`WordPress returned a malformed response for ${path}.`);
  }

  return data;
}

async function login(username: string, password: string): Promise<void> {
  if (!WORDPRESS_API_URL) {
    throw new Error("WORDPRESS_API_URL is not configured.");
  }

  let response: Response;
  try {
    response = await fetch(`${WORDPRESS_API_URL}/jwt-auth/v1/token`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ username, password }),
      cache: "no-store",
    });
  } catch {
    throw new Error("WordPress is unavailable. Please try again later.");
  }

  if (response.status === 403 || response.status === 401) {
    throw new WordPressAuthError();
  }

  if (!response.ok) {
    throw new Error("WordPress login failed. Please try again later.");
  }

  let data: unknown;
  try {
    data = await response.json();
  } catch {
    throw new Error("WordPress returned a malformed login response.");
  }

  const token =
    typeof data === "object" && data !== null && "token" in data
      ? (data as { token: unknown }).token
      : undefined;

  if (typeof token !== "string" || token.length === 0) {
    throw new Error("WordPress login response did not include a token.");
  }

  await setAuthCookie(token);
}

async function logout(): Promise<void> {
  await clearAuthCookie();
}

async function getMyTraining(): Promise<unknown> {
  return wordpressFetch("/platform-cle/v1/my-training", { auth: true });
}

export {
  wordpressFetch,
  login,
  logout,
  getMyTraining,
  WordPressAuthError,
  WordPressApiError,
};
