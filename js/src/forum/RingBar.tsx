import app from 'flarum/forum/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Avatar from 'flarum/common/components/Avatar';
import Link from 'flarum/common/components/Link';
import classList from 'flarum/common/utils/classList';
import type User from 'flarum/common/models/User';
import type Mithril from 'mithril';
import type Story from './models/Story';
import { failed, onStoriesChanged, t, tx, ui } from './util';

export interface Ring {
  user: User;
  /** The author's newest live story (the bar loads one per person; the viewer loads the rest). */
  latest: Story;
}

/** The bar's data, shared by every bar on the page (the index, a widget), loaded with one request. */
export const bar = {
  rings: [] as Ring[],
  mine: null as Story | null,
  loaded: false,
  loading: null as Promise<void> | null,

  load(): Promise<void> {
    this.loading ??= app.store
      .find<Story[]>('glimpse-stories', { filter: { bar: '1' }, page: { limit: 30, total: 0 } })
      .then((stories) => this.group(stories as Story[]))
      .catch(failed)
      .finally(() => {
        this.loaded = true;
        this.loading = null;
        m.redraw();
      });

    return this.loading;
  },

  group(stories: Story[]): void {
    const me = app.session.user;
    this.mine = null;
    this.rings = [];

    stories.forEach((story) => {
      const user = story.user();
      if (!user) return;
      if (me && user === me) this.mine = story;
      else this.rings.push({ user, latest: story });
    });
  },

  /** The ring order: unseen first, then people you follow, then newest; or simply newest (admin's choice). */
  ordered(): Ring[] {
    const smart = app.forum.attribute('glimpseBarOrder') !== 'newest';
    const time = (ring: Ring) => ring.latest.createdAt()?.getTime() || 0;

    return [...this.rings].sort((a, b) => {
      const unseen = Number(!!b.latest.authorHasUnseen()) - Number(!!a.latest.authorHasUnseen());
      const followed = Number(b.latest.authorFollowed()) - Number(a.latest.authorFollowed());
      return (smart && unseen) || (smart && followed) || time(b) - time(a);
    });
  },
};

onStoriesChanged(() => bar.load());

export interface RingBarAttrs extends ComponentAttrs {
  /** A smaller row without the heading, for widget hosts. */
  compact?: boolean;
}

/**
 * The row of avatar rings. Unseen rings have the gradient, seen ones are grey; your own ring comes
 * first, with a plus to add a story. A ring opens the viewer (a lazy chunk) on that person.
 */
export default class RingBar extends Component<RingBarAttrs> {
  oninit(vnode: Mithril.Vnode<RingBarAttrs, this>) {
    super.oninit(vnode);
    if (!bar.loaded) bar.load();
  }

  view() {
    const canCreate = !!app.forum.attribute('glimpseCanCreate');
    const rings = bar.ordered();

    if (bar.loaded && !rings.length && !bar.mine && !canCreate) return null;

    return (
      <section className={classList('GlimpseBar', this.attrs.compact && 'GlimpseBar--compact')} aria-label={tx('bar.label')}>
        {this.attrs.compact ? null : (
          <div className="GlimpseBar-head">
            <h2 className="GlimpseBar-title">{t('bar.title')}</h2>
            <Link className="GlimpseBar-all" href={app.route('glimpse.stories')}>
              {t('bar.see_all')}
            </Link>
          </div>
        )}
        <ul className="GlimpseBar-rings">
          {this.ownRing(canCreate)}
          {rings.map((ring) => this.ring(rings, ring))}
          {!bar.loaded ? <li className="GlimpseBar-item GlimpseBar-item--loading" aria-hidden="true" /> : null}
        </ul>
      </section>
    );
  }

  /** Your own ring: plays your stories, or (with nothing posted) opens the create sheet. */
  private ownRing(canCreate: boolean): Mithril.Children {
    const me = app.session.user;
    if (!me || (!canCreate && !bar.mine)) return null;
    const create = () => ui().then((chunk) => chunk.openCreate());

    return (
      <li className="GlimpseBar-item">
        <button
          type="button"
          className={classList('GlimpseRing', !bar.mine && 'GlimpseRing--empty')}
          aria-label={bar.mine ? tx('bar.your_story') : tx('bar.add')}
          onclick={() => (bar.mine ? this.openMine() : create())}
        >
          <span className="GlimpseRing-avatar">
            <Avatar user={me} />
          </span>
        </button>
        {canCreate && bar.mine ? (
          // A sibling, not inside the ring: a button can't hold another button.
          <button type="button" className="GlimpseRing-plus" aria-label={tx('bar.add')} onclick={create}>
            <i className="fas fa-plus" aria-hidden="true" />
          </button>
        ) : null}
        {canCreate && !bar.mine ? (
          <span className="GlimpseRing-plus" aria-hidden="true">
            <i className="fas fa-plus" />
          </span>
        ) : null}
        <span className="GlimpseRing-name">{t('bar.your_story')}</span>
      </li>
    );
  }

  private ring(rings: Ring[], ring: Ring): Mithril.Children {
    const unseen = !!ring.latest.authorHasUnseen();

    return (
      <li className="GlimpseBar-item">
        <button
          type="button"
          className={classList('GlimpseRing', !unseen && 'GlimpseRing--seen')}
          aria-label={tx(unseen ? 'bar.ring_new' : 'bar.ring_seen', { name: ring.user.displayName() })}
          onclick={() => this.open(rings, ring)}
        >
          <span className="GlimpseRing-avatar">
            <Avatar user={ring.user} />
          </span>
        </button>
        <span className={classList('GlimpseRing-name', !unseen && 'GlimpseRing-name--seen')}>{ring.user.displayName()}</span>
      </li>
    );
  }

  open(rings: Ring[], ring: Ring): void {
    // Closing the viewer reloads the bar, so what was just seen turns grey.
    ui().then((chunk) => chunk.openViewer({ users: rings.map((r) => r.user), start: ring.user, onclose: () => bar.load() }));
  }

  openMine(): void {
    const me = app.session.user;
    if (me) ui().then((chunk) => chunk.openViewer({ users: [me], start: me, onclose: () => bar.load() }));
  }
}
