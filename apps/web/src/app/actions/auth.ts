"use server";

import { redirect } from "next/navigation";

import { login, logout, WordPressAuthError } from "@/lib/wordpress";

type LoginActionState = {
  error?: string;
};

async function loginAction(
  _prevState: LoginActionState,
  formData: FormData
): Promise<LoginActionState> {
  const username = formData.get("username");
  const password = formData.get("password");

  if (typeof username !== "string" || typeof password !== "string") {
    return { error: "Username and password are required." };
  }

  try {
    await login(username, password);
  } catch (error) {
    if (error instanceof WordPressAuthError) {
      return { error: "Invalid username or password." };
    }

    return { error: "Unable to log in right now. Please try again later." };
  }

  redirect("/my-training");
}

async function logoutAction(): Promise<void> {
  await logout();
  redirect("/login");
}

export { loginAction, logoutAction };
