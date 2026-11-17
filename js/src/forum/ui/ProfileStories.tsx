import app from 'flarum/forum/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import type User from 'flarum/common/models/User';
import type Mithril from 'mithril';
import type Story from '../models/Story';
import type StoryHighlight from '../models/StoryHighlight';
import { failed, onStoriesChanged, t, tx } from '../util';
import { openViewer } from './mount';
import { openCreate } from './open';
import { storyCard } from './card';

interface ProfileStoriesAttrs extends ComponentAttrs {
  user: User;
}

/**
 * A member's Stories tab: their highlights row and their live stories. Your own tab also lists what is
 * waiting for approval, hidden, or kept only by a highlight, each marked.
 */
export default class ProfileStories extends Component<ProfileStoriesAttrs> {
  private stories: Story[] = [];
  private highlights: StoryHighlight[] = [];
  private loading = true;
  private unlisten: (() => void) | null = null;

  oninit(vnode: Mithril.Vnode<ProfileStoriesAttrs, this>) {
    super.oninit(vnode);
    this.load();
    this.unlisten = onStoriesChanged(() => this.load());
  }

  onremove(vnode: Mithril.VnodeDOM<ProfileStoriesAttrs, this>) {
    super.onremove(vnode);
    this.unlisten?.();
  }

  private own(): boolean {
    return !!app.session.user && this.attrs.user === app.session.user;
  }

  private load(): void {
    const id = this.attrs.user.id()!;
    this.loading = true;
    Promise.all([
      app.store.find<StoryHighlight[]>('glimpse-highlights', { filter: { user: id } }),
      app.store.find<Story[]>('glimpse-stories', {
        filter: this.own() ? { author: id } : { author: id, live: '1' },
        sort: 'createdAt',
        page: { limit: 100, total: 0 },
      }),
    ])
      .then(([highlights, stories]) => {
        this.highlights = [...highlights];
        this.stories = [...stories];
      })
      .catch(failed)
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    return (
      <div className="GlimpseProfile">
        {this.highlightsRow()}
        {this.liveStories()}
      </div>
    );
  }

  private highlightsRow(): Mithril.Children {
    const highlights = this.highlights.filter((h) => (h.stories() || []).some(Boolean));
    if (!highlights.length && !this.own()) return null;

    return (
      <section className="GlimpseProfile-section">
        <h2 className="GlimpseProfile-label">{t('highlights.title')}</h2>
        <ul className="GlimpseHighlights">{highlights.map((highlight) => this.highlightItem(highlight))}</ul>
        {this.own() && !highlights.length ? <p className="GlimpseProfile-hint">{t('highlights.hint')}</p> : null}
      </section>
    );
  }

  private highlightItem(highlight: StoryHighlight): Mithril.Children {
    const user = this.attrs.user;
    const stories = (highlight.stories() || []).filter((story): story is Story => !!story);
    const cover = stories[0];

    return (
      <li>
        <button
          type="button"
          className="GlimpseHighlights-item"
          aria-label={tx('highlights.play', { title: highlight.title() })}
          onclick={() => openViewer({ users: [user], start: user, stories, label: highlight.title() })}
        >
          <span className={classList('GlimpseHighlights-cover', cover?.kind() === 'text' && `GlimpseText GlimpseBg-${cover.background() ?? 0}`)}>
            {cover?.kind() === 'photo' ? <img src={cover.mediaUrl()!} alt="" loading="lazy" /> : null}
            {cover?.kind() === 'video' ? <video src={`${cover.mediaUrl()}#t=0.1`} preload="metadata" muted playsinline aria-hidden="true" /> : null}
          </span>
          <span className="GlimpseHighlights-title">{highlight.title()}</span>
        </button>
        {this.own() ? (
          <button
            type="button"
            className="GlimpseHighlights-remove"
            aria-label={tx('highlights.delete', { title: highlight.title() })}
            onclick={() => {
              if (confirm(tx('highlights.delete_confirm', { title: highlight.title() }))) highlight.delete().then(() => this.load(), failed);
            }}
          >
            <i className="fas fa-xmark" aria-hidden="true" />
          </button>
        ) : null}
      </li>
    );
  }

  private liveStories(): Mithril.Children {
    const user = this.attrs.user;
    let list: Mithril.Children;
    if (this.loading) list = <LoadingIndicator />;
    else if (!this.stories.length)
      list = <p className="GlimpsePage-empty">{t(this.own() ? 'profile.empty_own' : 'profile.empty', { name: user.displayName() })}</p>;
    else
      list = (
        <div className="GlimpseGrid">
          {this.stories.map((story) => storyCard(story, () => openViewer({ users: [user], start: user, stories: this.stories, startStory: story })))}
        </div>
      );

    return (
      <section className="GlimpseProfile-section">
        <div className="GlimpseProfile-row">
          <h2 className="GlimpseProfile-label">{t('profile.live')}</h2>
          {this.own() && app.forum.attribute('glimpseCanCreate') ? (
            <Button className="Button Button--primary" icon="fas fa-plus" onclick={() => openCreate()}>
              {t('page.add')}
            </Button>
          ) : null}
        </div>
        {list}
      </section>
    );
  }
}
