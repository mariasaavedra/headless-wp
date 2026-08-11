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

type Week = {
  id: number;
  title: string;
  excerpt: string;
  progress: Progress;
  modules: ModuleSummary[];
  events: SessionEvent[];
};

type WeekDetail = Week & {
  content: string;
  program: Ref | null;
};

type Program = {
  id: number;
  title: string;
  content: string;
  progress: Progress;
  weeks: Week[];
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
  week: Ref | null;
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
  Week,
  WeekDetail,
  Program,
  ModuleResource,
  ModuleDetail,
  TrainingProgram,
};
