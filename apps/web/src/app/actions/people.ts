"use server";

import { revalidatePath } from "next/cache";

import { setPersonRole, WordPressApiError } from "@/lib/wordpress";

type RoleActionState = {
  error?: string;
  /** What the change was, for a line confirming it happened. */
  changed?: { name: string; role: string };
};

/**
 * Changes what someone may do.
 *
 * Every refusal here is a rule the plugin keeps, and each is reported in its
 * own words: a reader who is told "that did not work" learns nothing about
 * which of the four things they tried was the problem.
 */
async function setRoleAction(
  _prevState: RoleActionState,
  formData: FormData
): Promise<RoleActionState> {
  const id = Number(formData.get("id"));
  const role = String(formData.get("role") ?? "");
  const name = String(formData.get("name") ?? "That person");

  if (!Number.isInteger(id) || id <= 0 || role === "") {
    return { error: "That person could not be identified." };
  }

  try {
    const person = await setPersonRole(id, role);

    revalidatePath("/people");

    return { changed: { name: person.name, role: person.role_label } };
  } catch (error) {
    if (error instanceof WordPressApiError) {
      switch (error.code) {
        case "pcle_cannot_change_own_role":
          return { error: "You cannot change your own role." };
        case "pcle_cannot_change_administrator":
          return {
            error:
              "An administrator's role is changed in WordPress, not here.",
          };
        case "pcle_cannot_promote":
          return { error: "Only an administrator may change a role." };
        case "pcle_role_not_grantable":
        case "rest_invalid_param":
          return { error: "That role cannot be granted from here." };
        default:
          break;
      }
    }

    return { error: `${name}'s role could not be changed. Please try again.` };
  }
}

export { setRoleAction };
export type { RoleActionState };
