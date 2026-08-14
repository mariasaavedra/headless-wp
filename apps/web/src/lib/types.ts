/**
 * Shapes returned by the Platform CLE REST API (`platform-cle/v1`).
 *
 * These mirror the shaping helpers in the plugin's `includes/rest.php`. The
 * `content` fields carry HTML rendered by WordPress, authored by instructors
 * and administrators in wp-admin.
 */

type Progress = {
  completed: number;
  total: number;
  percentage: number;
};

/** A bare pointer to another post, used for breadcrumbs. */
type Ref = {
  id: number;
  title: string;
};

type ModuleSummary = {
  id: number;
  title: string;
  excerpt: string;
  completed: boolean;
};

type SessionEvent = {
  id: number;
  title: string;
  /** ISO 8601, empty when the event has no date set. */
  starts_at: string;
  /** Pre-formatted in the site's own locale and timezone. */
  formatted: string;
};

type Unit = {
  id: number;
  title: string;
  excerpt: string;
  progress: Progress;
  modules: ModuleSummary[];
  events: SessionEvent[];
};

type UnitDetail = Unit & {
  content: string;
  program: Ref | null;
};

type Program = {
  id: number;
  title: string;
  content: string;
  progress: Progress;
  units: Unit[];
};

/** A practice scenario or a template hanging off a module. */
type ModuleResource = {
  id: number;
  title: string;
  content: string;
};

type ModuleDetail = {
  id: number;
  title: string;
  content: string;
  completed: boolean;
  unit: Ref | null;
  program: Ref | null;
  scenarios: ModuleResource[];
  templates: ModuleResource[];
};

/** An entry of the signed-in user's programme list. */
type TrainingProgram = {
  id: number;
  title: string;
  progress: Progress;
};

export type {
  Progress,
  Ref,
  ModuleSummary,
  SessionEvent,
  Unit,
  UnitDetail,
  Program,
  ModuleResource,
  ModuleDetail,
  TrainingProgram,
};

/* ------------------------------------------------------------------ */
/* Authoring                                                           */
/* ------------------------------------------------------------------ */

/** Who is signed in, and what the app should offer them. */
type Me = {
  id: number;
  display_name: string;
  roles: string[];
  can_author: boolean;
  is_admin: boolean;
};

/** The curriculum post types the builder manages. */
type NodeType =
  | "pcle_program"
  | "pcle_unit"
  | "pcle_module"
  | "pcle_scenario"
  | "pcle_template"
  | "pcle_event";

/** A programme as it appears in the builder's list. */
type AuthoringProgram = {
  id: number;
  title: string;
  status: string;
  credits: CreditHours[];
  units: number;
  modules: number;
  enrollees: number;
};

type CreditHours = {
  jurisdiction: string;
  label: string;
  hours: number;
};

/**
 * One node of the curriculum tree.
 *
 * `allowed_children` comes from the server rather than being restated here,
 * so the add menu and any future drop rules cannot drift from what the API
 * will actually accept.
 */
type TreeNode = {
  id: number;
  type: NodeType;
  title: string;
  status: string;
  menu_order: number;
  allowed_children: NodeType[];
  children: TreeNode[];
  /** Events only. */
  starts_at?: string;
  /** Scenarios only. */
  has_model_answer?: boolean;
  /** Programmes only. */
  credits?: CreditHours[];
};

/**
 * One node opened for editing.
 *
 * `body` is the authored text, not HTML — the server turns it into block
 * markup. `editable` is false when the stored content contains something the
 * builder cannot express, in which case it must be shown read-only.
 */
type NodeDetail = TreeNode & {
  body: string;
  editable: boolean;
  excerpt: string;
  rendered: string;
  parent: Ref | null;
  program: Ref | null;
};

export type {
  Me,
  NodeType,
  AuthoringProgram,
  CreditHours,
  TreeNode,
  NodeDetail,
};
