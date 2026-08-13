import type { NodeType } from "@/lib/types";

/**
 * Human names for the curriculum types.
 *
 * The API speaks in post types; an instructor should never have to.
 */
const NODE_LABELS: Record<NodeType, { singular: string; plural: string }> = {
  pcle_program: { singular: "Programme", plural: "Programmes" },
  pcle_week: { singular: "Week", plural: "Weeks" },
  pcle_module: { singular: "Module", plural: "Modules" },
  pcle_scenario: { singular: "Practice scenario", plural: "Practice scenarios" },
  pcle_template: { singular: "Template", plural: "Templates" },
  pcle_event: { singular: "Live session", plural: "Live sessions" },
};

/** A short badge for a row. */
const NODE_BADGES: Record<NodeType, string> = {
  pcle_program: "Programme",
  pcle_week: "Week",
  pcle_module: "Module",
  pcle_scenario: "Scenario",
  pcle_template: "Template",
  pcle_event: "Session",
};

export { NODE_LABELS, NODE_BADGES };
