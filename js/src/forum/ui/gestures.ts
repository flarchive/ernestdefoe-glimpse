/**
 * Touch and mouse on the story card: tap the right two thirds to go on, the left third to go back,
 * hold to pause, drag down to close, swipe sideways to change person. Pointer events, so one code path
 * serves mouse, pen and touch.
 */
export interface GestureTarget {
  card(): HTMLElement | null;
  hold(on: boolean): void;
  forward(): void;
  back(): void;
  nextPerson(): void;
  previousPerson(): void;
  close(): void;
}

const HOLD_MS = 200;
const SWIPE_PX = 70;
const CLOSE_PX = 90;
const MOVE_PX = 8;

export default class Gestures {
  holding = false;
  private start: { x: number; y: number; moved: boolean; timer: number } | null = null;

  constructor(private readonly target: GestureTarget) {}

  down(e: PointerEvent): void {
    // Buttons, links, the reply field and the menus handle their own presses.
    if ((e.target as HTMLElement).closest('button, a, input, .GlimpseViewer-menu, .GlimpseViewer-reactions')) return;
    this.holding = false;
    this.start = {
      x: e.clientX,
      y: e.clientY,
      moved: false,
      timer: window.setTimeout(() => {
        this.holding = true;
        this.target.hold(true);
      }, HOLD_MS),
    };
  }

  move(e: PointerEvent): void {
    if (!this.start) return;
    const dx = e.clientX - this.start.x;
    const dy = e.clientY - this.start.y;
    if (Math.abs(dx) > MOVE_PX || Math.abs(dy) > MOVE_PX) {
      this.start.moved = true;
      clearTimeout(this.start.timer);
    }
    this.drag(Math.max(0, dy));
  }

  up(e: PointerEvent): void {
    const start = this.start;
    this.start = null;
    this.drag(0);
    if (!start) return;
    clearTimeout(start.timer);

    const dx = e.clientX - start.x;
    const dy = e.clientY - start.y;
    const wasHold = this.release();

    if (dy > CLOSE_PX && Math.abs(dy) > Math.abs(dx)) return this.target.close();
    if (Math.abs(dx) > SWIPE_PX && Math.abs(dx) > Math.abs(dy)) return dx < 0 ? this.target.nextPerson() : this.target.previousPerson();

    const card = this.target.card();
    if (wasHold || start.moved || !card) return;
    const rect = card.getBoundingClientRect();
    if (e.clientX - rect.left > rect.width / 3) this.target.forward();
    else this.target.back();
  }

  cancel(): void {
    if (this.start) clearTimeout(this.start.timer);
    this.start = null;
    this.drag(0);
    this.release();
  }

  /** Ends a hold, if one was on; says whether it was. */
  private release(): boolean {
    if (!this.holding) return false;
    this.holding = false;
    this.target.hold(false);
    return true;
  }

  /** Follows the finger while dragging down, as a hint that letting go closes. */
  private drag(dy: number): void {
    const card = this.target.card();
    if (card) card.style.transform = dy ? `translateY(${dy}px) scale(${1 - dy / 1500})` : '';
  }
}

/** The viewer's keys: ← → to move, Space to pause, Esc to close. Typing in a field or a modal keeps its keys. */
export function keys(e: KeyboardEvent, on: { forward(): void; back(): void; pause(): void; escape(): void }): void {
  if ((e.target as HTMLElement | null)?.closest('input, textarea, .ModalManager')) return;
  const action = { ArrowRight: on.forward, ArrowLeft: on.back, ' ': on.pause, Escape: on.escape }[e.key];
  if (!action) return;
  e.preventDefault();
  action();
}
