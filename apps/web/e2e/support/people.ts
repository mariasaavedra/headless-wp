/**
 * Who these tests sign in as.
 *
 * The demo seeder creates these when the stack is first installed, so the
 * fixtures are the ones a developer already has and the ones CI builds from
 * `.env.example`. Overridable, because a machine with different demo
 * credentials should be able to run the suite rather than fail it.
 */
const DEMO_PASSWORD = process.env.PCLE_DEMO_USER_PASSWORD ?? "demo1234";

const people = {
  /** Enrolled in a programme. Sees My Training and nothing else. */
  participant: {
    username: "demo.student",
    password: DEMO_PASSWORD,
  },
  /** Teaches: may build and report, may not create accounts. */
  instructor: {
    username: "demo.instructor",
    password: DEMO_PASSWORD,
  },
  /** Everything, including creating accounts. */
  administrator: {
    username: process.env.WORDPRESS_ADMIN_USER ?? "admin",
    password: process.env.WORDPRESS_ADMIN_PASSWORD ?? "admin",
  },
} as const;

type Person = (typeof people)[keyof typeof people];

export { people };
export type { Person };
