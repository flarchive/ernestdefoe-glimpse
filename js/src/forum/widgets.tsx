import app from 'flarum/forum/app';
import RingBar from './RingBar';
import { EXT } from './util';
import { defaultExport, has, runtime } from '../common/runtime';

/*
 * The ring bar offered to every widget host that is present: fof/forum-widgets-core, Bespoke and Page
 * Builder. None is a build-time import; each is found at runtime, so forum.js is the same with all
 * three installed or none. FoF and Page Builder are optional dependencies in composer.json, so their
 * initializers have run before ours.
 */

const KEY = 'glimpseBar';
const TYPE = 'glimpse-bar';
const TITLE = `${EXT}.forum.widget.title`;
const ICON = 'fas fa-circle-play';

const widget = { view: () => <RingBar compact /> };

export function registerWidgets(): void {
  for (const register of [registerFof, registerBespoke, registerPageBuilder]) {
    try {
      register();
    } catch (e) {
      // A host whose internals moved must not take the forum down with it.
      console.warn('[glimpse] widget host registration failed', e);
    }
  }
}

/** The parts of fof/forum-widgets-core used here, looked up at runtime. */
type WidgetBaseClass = new (...args: never[]) => object;
type WidgetsExtender = new () => { add(widget: object): { extend(app: unknown, extension: string): void } };
type WidgetHost = { widgets?: { config?: { instances?: Array<{ id?: string } | null> } } };

/** The queues Bespoke and Page Builder drain on every lookup, so the order extensions boot in doesn't matter. */
const queues = globalThis as unknown as { BespokeWidgetQueue?: object[]; PageBuilderBlockQueue?: object[] };

function fof<T>(path: string): T | undefined {
  const mod = runtime()?.reg.get('fof-forum-widgets-core', path);
  return mod ? defaultExport<T>(mod) : undefined;
}

function registerFof(): void {
  if (!has('fof-forum-widgets-core')) return;
  // FoF's classes come from the module registry (flarum.reg), never a build-time import of FoF.
  const Widgets = fof<WidgetsExtender>('common/extend/Widgets');
  const WidgetBase = fof<WidgetBaseClass>('common/components/Widget');
  const host = app as unknown as WidgetHost;
  if (!Widgets || !WidgetBase || !host.widgets) return;

  class GlimpseFofWidget extends WidgetBase {
    className() {
      return 'GlimpseFofWidget';
    }
    icon() {
      return ICON;
    }
    title() {
      return app.translator.trans(TITLE);
    }
    content() {
      return <RingBar compact />;
    }
  }

  new Widgets()
    .add({
      key: KEY,
      component: GlimpseFofWidget,
      // Opt-in: shown only where the admin placed it in FoF's layout editor.
      isDisabled: () => !(host.widgets?.config?.instances || []).some((instance) => instance?.id === `${EXT}:${KEY}`),
      isUnique: true,
      placement: 'top',
      position: 0,
    })
    .extend(app, EXT);
}

function registerBespoke(): void {
  if (!has('ernestdefoe-bespoke')) return;
  // Bespoke's documented hook: it drains this queue on every registry lookup. Labels are translation
  // keys, not translated text (Bespoke translates them itself).
  (queues.BespokeWidgetQueue ||= []).push({
    type: TYPE,
    label: TITLE,
    icon: ICON,
    zones: ['hero', 'top', 'above-list', 'below-list'],
    schema: [],
    component: widget,
  });
}

function registerPageBuilder(): void {
  if (!has('ernestdefoe-page-builder')) return;
  // Page Builder drains this queue on every lookup, so the order extensions boot in doesn't matter.
  // `type` matches Widget\StoriesBlock::type() on the server.
  (queues.PageBuilderBlockQueue ||= []).push({ type: TYPE, component: widget });
}
