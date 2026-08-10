import { cookies } from "next/headers";

const AUTH_COOKIE_NAME = "platform_cle_token";

function getTokenExpiry(token: string): Date | undefined {
  const payload = token.split(".")[1];
  if (!payload) return undefined;

  try {
    const decoded = JSON.parse(
      Buffer.from(payload, "base64url").toString("utf8")
    );
    return typeof decoded.exp === "number"
      ? new Date(decoded.exp * 1000)
      : undefined;
  } catch {
    return undefined;
  }
}

async function setAuthCookie(token: string) {
  const cookieStore = await cookies();

  cookieStore.set(AUTH_COOKIE_NAME, token, {
    httpOnly: true,
    sameSite: "lax",
    path: "/",
    secure: process.env.NODE_ENV === "production",
    expires: getTokenExpiry(token),
  });
}

async function clearAuthCookie() {
  const cookieStore = await cookies();
  cookieStore.delete(AUTH_COOKIE_NAME);
}

async function getAuthToken(): Promise<string | null> {
  const cookieStore = await cookies();
  return cookieStore.get(AUTH_COOKIE_NAME)?.value ?? null;
}

async function isAuthenticated(): Promise<boolean> {
  return (await getAuthToken()) !== null;
}

export { setAuthCookie, clearAuthCookie, getAuthToken, isAuthenticated };
