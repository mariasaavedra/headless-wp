"use client";

import {
  createContext,
  useContext,
  useRef,
  useState,
  useTransition,
  type ReactNode,
} from "react";
import {
  closestCenter,
  DndContext,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
  type Announcements,
  type DragEndEvent,
} from "@dnd-kit/core";
import { restrictToParentElement, restrictToVerticalAxis } from "@dnd-kit/modifiers";
import {
  arrayMove,
  SortableContext,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from "@dnd-kit/sortable";
import { CSS } from "@dnd-kit/utilities";
import { GripVerticalIcon } from "lucide-react";

import { cn } from "@pcle/ui/lib/utils";

import { reorderAction } from "@/app/actions/authoring";
import type { NodeType } from "@/lib/types";

/**
 * One list of siblings that can be reordered by dragging.
 *
 * A list is one type under one parent — the same unit the reorder endpoint
 * works in (see tree-view.tsx) — so a row can be dragged among its siblings
 * but not into another list. Moving an item to a different parent is a
 * different operation the API does not offer.
 *
 * The rows themselves are rendered on the server and passed in; only the
 * dragging is interactive. The new order is shown at once and saved behind
 * it. If the save is refused the old order comes back and the list says so,
 * because an author who sees a change that did not stick will close the tab
 * believing it did.
 *
 * The Move up / Move down buttons stay. Dragging also works from the keyboard
 * (space on the handle, arrows, space again), but buttons are the version
 * nobody has to be told about.
 */

type SortableRow = { id: number; label: string; content: ReactNode };

/**
 * Lets the grip, built where the drag state lives, sit inside a row rendered
 * on the server. Null means "no grip here".
 */
const HandleContext = createContext<ReactNode>(null);

function SortableItem({
  id,
  label,
  children,
}: {
  id: number;
  label: string;
  children: ReactNode;
}) {
  const {
    attributes,
    listeners,
    setNodeRef,
    setActivatorNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({ id });

  /*
   * Only the grip starts a drag, not the whole row: the row is full of links
   * and buttons, and a click on any of them must stay a click.
   */
  const handle = (
    <button
      type="button"
      ref={setActivatorNodeRef}
      {...attributes}
      {...listeners}
      aria-label={`Drag to reorder ${label}`}
      className="size-5 shrink-0 cursor-grab touch-none rounded text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 active:cursor-grabbing"
    >
      <GripVerticalIcon className="size-4" />
    </button>
  );

  return (
    <li
      ref={setNodeRef}
      style={{ transform: CSS.Translate.toString(transform), transition }}
      className={cn(
        "relative border-t border-zinc-100 bg-white first:border-t-0",
        isDragging && "z-10 rounded-md shadow-md ring-1 ring-zinc-200"
      )}
    >
      <HandleContext.Provider value={handle}>{children}</HandleContext.Provider>
    </li>
  );
}

/** Where a row puts its grip. Renders nothing for a row alone in its list. */
export function DragHandle() {
  return useContext(HandleContext);
}

export default function SortableGroup({
  parentId,
  childType,
  rows,
}: {
  parentId: number;
  childType: NodeType;
  rows: SortableRow[];
}) {
  const serverOrder = rows.map((row) => row.id);
  const [order, setOrder] = useState(serverOrder);
  const [lastServerOrder, setLastServerOrder] = useState(serverOrder);
  const [error, setError] = useState<string | null>(null);
  const [saving, startSaving] = useTransition();

  /*
   * Follow the server when it changes its mind — an item added, deleted, or
   * moved with the buttons. Done during render rather than in an effect so
   * the list never paints one frame in the stale order.
   */
  if (serverOrder.join(",") !== lastServerOrder.join(",")) {
    setLastServerOrder(serverOrder);
    setOrder(serverOrder);
  }

  const sensors = useSensors(
    // A few pixels of travel first, so a click on the handle is not a drag.
    useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
    useSensor(KeyboardSensor, {
      coordinateGetter: sortableKeyboardCoordinates,
    })
  );

  const byId = new Map(rows.map((row) => [row.id, row.content]));
  const labels = new Map(rows.map((row) => [row.id, row.label]));

  /*
   * What a screen reader hears. dnd-kit's defaults read out the internal IDs
   * ("item 2541 was moved over 2539"); an author needs the titles and where
   * the item now sits.
   */
  const name = (id: string | number) => labels.get(Number(id)) ?? "item";
  const place = (id: string | number) =>
    `position ${order.indexOf(Number(id)) + 1} of ${order.length}`;
  /*
   * dnd-kit reports "over" as soon as an item is picked up — over itself — and
   * again for every pixel of travel. Only a change of place is news; saying
   * the rest would talk over "Picked up".
   */
  const lastOver = useRef<string | number | null>(null);
  const announcements: Announcements = {
    onDragStart: ({ active }) => {
      lastOver.current = active.id;
      return `Picked up ${name(active.id)}, ${place(active.id)}.`;
    },
    onDragOver: ({ active, over }) => {
      if ((over?.id ?? null) === lastOver.current) {
        return undefined;
      }
      lastOver.current = over?.id ?? null;
      return over
        ? `${name(active.id)} moved to ${place(over.id)}.`
        : `${name(active.id)} is no longer over the list.`;
    },
    onDragEnd: ({ active, over }) =>
      over ? `${name(active.id)} dropped at ${place(over.id)}.` : `${name(active.id)} dropped where it was.`,
    onDragCancel: ({ active }) => `Moving ${name(active.id)} cancelled. It is back where it was.`,
  };

  function onDragEnd({ active, over }: DragEndEvent) {
    if (!over || active.id === over.id) {
      return;
    }

    const previous = order;
    const next = arrayMove(
      order,
      order.indexOf(Number(active.id)),
      order.indexOf(Number(over.id))
    );

    setOrder(next);
    setError(null);

    startSaving(async () => {
      const form = new FormData();
      form.set("parent_id", String(parentId));
      form.set("child_type", childType);
      form.set("ids", next.join(","));

      const result = await reorderAction({}, form);

      if (result.error) {
        setOrder(previous);
        setError(result.error);
      }
    });
  }

  const list = (
    <ul aria-busy={saving}>
      {order.map((id) =>
        rows.length > 1 ? (
          <SortableItem key={id} id={id} label={labels.get(id) ?? ""}>
            {byId.get(id)}
          </SortableItem>
        ) : (
          <li key={id} className="border-t border-zinc-100 first:border-t-0">
            {/*
              Explicitly no handle. Without this the grip would find the
              nearest item above it — this row's parent — and drag that.
            */}
            <HandleContext.Provider value={null}>
              {byId.get(id)}
            </HandleContext.Provider>
          </li>
        )
      )}
    </ul>
  );

  return (
    <>
      {rows.length > 1 ? (
        <DndContext
          sensors={sensors}
          collisionDetection={closestCenter}
          modifiers={[restrictToVerticalAxis, restrictToParentElement]}
          onDragEnd={onDragEnd}
          accessibility={{
            announcements,
            screenReaderInstructions: {
              draggable:
                "To reorder, press space to pick this item up, use the arrow keys to move it, and press space again to drop it. Press escape to cancel.",
            },
          }}
        >
          <SortableContext items={order} strategy={verticalListSortingStrategy}>
            {list}
          </SortableContext>
        </DndContext>
      ) : (
        list
      )}

      {error && (
        <p role="alert" className="mt-2 text-sm text-red-700">
          {error}
        </p>
      )}
    </>
  );
}
