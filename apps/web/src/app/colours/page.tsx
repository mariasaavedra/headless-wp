import Link from "next/link";
import { redirect } from "next/navigation";
import type { ReactNode } from "react";

import { Card, CardContent } from "@pcle/ui/components/card";

import { setDefaultColourAction } from "@/app/actions/colours";
import { renderAccessError } from "@/components/access-error";
import ColourMenu from "@/components/colours/colour-menu";
import DisplayControls from "@/components/colours/display-controls";
import SwatchMenu from "@/components/colours/swatch-menu";
import { NODE_LABELS } from "@/components/builder/node-labels";
import PageShell from "@/components/page-shell";
import { FormatBadge, KindBadge, StateBadge } from "@/components/state-badge";
import { isAuthenticated } from "@/lib/auth";
import {
  KINDS,
  STATES,
  swatchLabel,
  type Hue,
  type Kind,
  type State,
  type Swatch,
} from "@/lib/colours";
import { decodeEntities } from "@/lib/html";
import type { AuthoringProgram } from "@/lib/types";
import { getAuthoringPrograms, getColourSettings, getMe } from "@/lib/wordpress";

export const dynamic = "force-dynamic";

const KIND_HINTS: Record<Kind, string> = {
  pcle_unit: "A chapter of a programme.",
  pcle_module: "One lesson: a video, a reading, or both.",
  pcle_quiz: "Checks understanding; can be required to finish a module.",
  pcle_event: "A scheduled live session.",
  pcle_scenario: "A written exercise with a model answer.",
  pcle_template: "A document participants can download and adapt.",
};

/** "amber, gold, rust or coral" */
function familyInWords(state: State): string {
  const family = STATES[state].family;

  if (!family) {
    return "Any colour, or none";
  }

  return `Any ${family.slice(0, -1).join(", ")} or ${family.at(-1)} colour`;
}

function Section({
  title,
  description,
  locked,
  children,
}: {
  title: string;
  description: string;
  locked?: string;
  children: ReactNode;
}) {
  return (
    <Card className="mt-8 p-0">
      <CardContent className="p-0">
        <div className="px-6 pt-6 pb-2">
          <h2 className="text-lg font-semibold text-zinc-950">{title}</h2>
          <p className="mt-1 text-sm text-zinc-600">{description}</p>
          {locked && <p className="mt-3 rounded-md bg-zinc-100 px-3 py-2 text-sm text-zinc-600">{locked}</p>}
        </div>
        {children}
      </CardContent>
    </Card>
  );
}

function SettingRow({
  name,
  hint,
  sample,
  control,
}: {
  name: string;
  hint?: string;
  sample?: ReactNode;
  control: ReactNode;
}) {
  return (
    <div className="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-zinc-100 px-6 py-3">
      <div className="min-w-0 flex-1">
        <div className="font-medium text-zinc-900">{name}</div>
        {hint && <div className="text-sm text-zinc-500">{hint}</div>}
      </div>
      {sample}
      {control}
    </div>
  );
}

/** A swatch menu for one site-wide default, with its colour said in words. */
function DefaultControl({
  group,
  setting,
  label,
  current,
  allowedHues,
  disabled,
}: {
  group: "kinds" | "formats" | "states";
  setting: string;
  label: string;
  current: Swatch | null;
  allowedHues?: Hue[] | null;
  disabled: boolean;
}) {
  return (
    <SwatchMenu
      action={setDefaultColourAction}
      fields={{ group, key: setting }}
      current={current}
      shown={current}
      label={label}
      now={current ? swatchLabel(current) : "No colour"}
      clear={{ label: "No colour", swatch: null }}
      allowedHues={allowedHues}
      disabled={disabled}
    />
  );
}

/**
 * The Colours screen: every site-wide colour choice in one place.
 *
 * Authors colour their programmes here as well as in the builder. The
 * defaults below, which every screen's badges are drawn from, are an
 * administrator's; everyone else sees them as they are, with the reason they
 * cannot change them, rather than controls that would be refused.
 */
export default async function ColoursPage() {
  if (!(await isAuthenticated())) {
    redirect("/login");
  }

  let programs: AuthoringProgram[];

  try {
    programs = (await getAuthoringPrograms()).programs;
  } catch (error) {
    return renderAccessError(error, {
      title: "You do not have access to the colours",
      detail:
        "Colours are chosen by instructors and administrators. If you think that should include you, contact your programme administrator.",
    });
  }

  const [me, settings] = await Promise.all([getMe(), getColourSettings()]);
  const admin = me.is_admin;
  const locked = admin ? undefined : "Only administrators change these.";

  return (
    <PageShell code="P16">
      <h1 className="text-3xl font-semibold tracking-tight text-zinc-950">
        Colours
      </h1>

      <p className="mt-2 text-zinc-600">
        How programmes, what is in them, and how far along people are, are
        coloured across Platform CLE. Every colour comes from the same 32
        named swatches; a choice is saved the moment it is made.
      </p>

      <Section
        title="Programmes"
        description="A programme's colour runs down the side of its card and of everything inside it. To give one unit, module or quiz a colour of its own, use the dot beside it in the builder."
      >
        {programs.length === 0 ? (
          <p className="border-t border-zinc-100 px-6 py-4 text-sm text-zinc-500">
            There are no programmes yet.
          </p>
        ) : (
          programs.map((program) => (
            <SettingRow
              key={program.id}
              name={decodeEntities(program.title)}
              hint={program.status === "publish" ? undefined : "Draft"}
              control={
                <>
                  <Link
                    href={`/builder/programs/${program.id}`}
                    className="text-sm text-zinc-500 underline-offset-2 hover:underline"
                  >
                    Open in builder
                  </Link>
                  <ColourMenu
                    nodeId={program.id}
                    title={decodeEntities(program.title)}
                    own={program.color}
                    inherited={null}
                    inheritedFrom={null}
                  />
                </>
              }
            />
          ))
        )}
      </Section>

      <Section
        title="Kinds of item"
        description="The badge that says what something is, in the builder and on the item's own page."
        locked={locked}
      >
        {KINDS.map((kind) => (
          <SettingRow
            key={kind}
            name={NODE_LABELS[kind].singular}
            hint={KIND_HINTS[kind]}
            sample={<KindBadge kind={kind} />}
            control={
              <DefaultControl
                group="kinds"
                setting={kind}
                label={`${NODE_LABELS[kind].singular} badges`}
                current={settings.kinds[kind]}
                disabled={!admin}
              />
            }
          />
        ))}
        <SettingRow
          name="Webinar"
          hint="Marks a programme that is one recorded video."
          sample={<FormatBadge format="webinar" />}
          control={
            <DefaultControl
              group="formats"
              setting="webinar"
              label="Webinar badges"
              current={settings.formats.webinar}
              disabled={!admin}
            />
          }
        />
      </Section>

      <Section
        title="States"
        description="The badge that says where something stands. It always says it in words too. Each state keeps to its own family of colours, so a quiz that was not passed can never look like one that was."
        locked={locked}
      >
        {(Object.keys(STATES) as State[]).map((state) => (
          <SettingRow
            key={state}
            name={STATES[state].label}
            hint={familyInWords(state)}
            sample={<StateBadge state={state} />}
            control={
              <DefaultControl
                group="states"
                setting={state}
                label={`“${STATES[state].label}”`}
                current={settings.states[state]}
                allowedHues={STATES[state].family}
                disabled={!admin}
              />
            }
          />
        ))}
      </Section>

      <Section
        title="Display"
        description="How programme and item colours are drawn, and who sees them."
        locked={locked}
      >
        <div className="border-t border-zinc-100 px-6 py-5">
          <DisplayControls settings={settings} disabled={!admin} />
        </div>
      </Section>
    </PageShell>
  );
}
