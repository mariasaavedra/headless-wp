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
  | "pcle_quiz"
  | "pcle_template"
  | "pcle_event";

/** How a quiz question is answered, and whether the server can mark it. */
type QuizQuestionType = "single" | "multiple" | "text";

/**
 * One option of a choice question.
 *
 * `correct` is present only in the authoring shape. The endpoint a participant
 * reads strips it, along with the per-question feedback — see
 * `pcle_quiz_questions_for_taking()` in the plugin.
 */
type QuizChoice = {
  key: string;
  text: string;
  correct: boolean;
};

/** One question, as its author sees it. */
type QuizQuestion = {
  key: string;
  type: QuizQuestionType;
  prompt: string;
  help: string;
  /** Shown after answering, so it often gives the answer away. */
  feedback: string;
  required: boolean;
  choices: QuizChoice[];
};

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
  /** Quizzes only: how many questions have been written. */
  questions?: number;
  /** Quizzes only: whether passing is required to complete the module. */
  gates_completion?: boolean;
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
type NodeDetail = Omit<TreeNode, "questions"> & {
  body: string;
  editable: boolean;
  excerpt: string;
  rendered: string;
  parent: Ref | null;
  program: Ref | null;
  /**
   * Quizzes only. The tree carries a count; opening the node carries the
   * questions themselves, answers included, because this route is staff-only.
   */
  questions?: QuizQuestion[];
  pass_mark?: number;
  max_score?: number;
};

export type {
  Me,
  NodeType,
  QuizQuestionType,
  QuizChoice,
  QuizQuestion,
  AuthoringProgram,
  CreditHours,
  TreeNode,
  NodeDetail,
};
