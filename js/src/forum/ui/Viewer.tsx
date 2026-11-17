import app from 'flarum/forum/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Avatar from 'flarum/common/components/Avatar';
import Link from 'flarum/common/components/Link';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import humanTime from 'flarum/common/helpers/humanTime';
import type User from 'flarum/common/models/User';
import type { ApiPayloadSingle } from 'flarum/common/Store';
import type Mithril from 'mithril';
import type Story from '../models/Story';
import { failed, t, tx } from '../util';
import Gestures, { keys } from './gestures';
import caption from './caption';
import ReplyBar from './ReplyBar';
import ViewerMenu from './ViewerMenu';
import { AUDIENCE_ICONS, hostOf, preload, trackSeen } from './viewerTools';
import { openSeenBy } from './modals';

export interface ViewerOptions {
  /** The people to play, in order. */
  users: User[];
  /** Whose stories to start with. */
  start: User;
  /** Stories already in hand for the start person (your own ring, a highlight): nothing is fetched for them. */
  stories?: Story[];
  /** Which of those to begin at. */
  startStory?: Story;
  /** A highlight's title, shown under the name. */
  label?: string;
  onclose?: () => void;
}

interface ViewerAttrs extends ComponentAttrs {
  options: ViewerOptions;
  close: () => void;
}

const SEEN_FLUSH_MS = 1500;

/**
 * The full-screen story player. Touch and mouse are Gestures' job; keys here: ← → Space Esc. Photos and text stay up for the admin's
 * time, a video for its length (never past the admin's limit). The next story's media is fetched while
 * this one plays, and the next person's stories while their last one plays. Stories are marked seen in
 * batches, not one request each.
 */
export default class Viewer extends Component<ViewerAttrs> {
  private users: User[] = [];
  private userIndex = 0;
  private stories: Story[] = [];
  private index = 0;
  private loading = false;
  private cache = new Map<string, Promise<Story[] | null>>();

  private elapsed = 0;
  private duration = 6000;
  private last = 0;
  private raf = 0;
  private paused = false;
  private muted = true;
  private menuOpen = false;
  private reacting = false;
  private toast: string | null = null;
  private toastTimer = 0;

  private seen = new Set<string>();
  private seenTimer = 0;
  private gestures = new Gestures({
    card: () => this.element?.querySelector<HTMLElement>('.GlimpseViewer-card') ?? null,
    hold: (on) => this.setPaused(on),
    forward: () => this.next(),
    back: () => this.previous(),
    nextPerson: () => this.nextUser(),
    previousPerson: () => this.previousUser(),
    close: () => this.attrs.close(),
  });
  private previousFocus: Element | null = null;
  private keyHandler = (e: KeyboardEvent) => this.onkey(e);

  oninit(vnode: Mithril.Vnode<ViewerAttrs, this>) {
    super.oninit(vnode);
    const { users, start, stories, startStory } = this.attrs.options;
    this.users = users;
    this.userIndex = Math.max(0, users.indexOf(start));
    if (stories) this.cache.set(start.id()!, Promise.resolve(stories));
    this.loadUser(startStory);
  }

  oncreate(vnode: Mithril.VnodeDOM<ViewerAttrs, this>) {
    super.oncreate(vnode);
    this.previousFocus = document.activeElement;
    document.addEventListener('keydown', this.keyHandler);
    document.body.classList.add('GlimpseViewer-open');
    (vnode.dom.querySelector('.GlimpseViewer-close') as HTMLElement | null)?.focus();
    this.last = performance.now();
    this.raf = requestAnimationFrame((now) => this.tick(now));
  }

  onremove(vnode: Mithril.VnodeDOM<ViewerAttrs, this>) {
    super.onremove(vnode);
    cancelAnimationFrame(this.raf);
    clearTimeout(this.toastTimer);
    document.removeEventListener('keydown', this.keyHandler);
    document.body.classList.remove('GlimpseViewer-open');
    this.flushSeen();
    (this.previousFocus as HTMLElement | null)?.focus?.();
  }

  // ---------- data ----------

  private user(): User | undefined {
    return this.users[this.userIndex];
  }

  private story(): Story | undefined {
    return this.stories[this.index];
  }

  /** The person's live stories, oldest first; null when they couldn't be loaded. */
  private storiesOf(user: User): Promise<Story[] | null> {
    const id = user.id()!;
    if (!this.cache.has(id)) {
      this.cache.set(
        id,
        app.store
          .find<Story[]>('glimpse-stories', { filter: { author: id, live: '1' }, sort: 'createdAt', page: { limit: 100, total: 0 } })
          .then((stories) => [...(stories as Story[])])
          .catch(() => {
            failed();
            return null;
          })
      );
    }
    return this.cache.get(id)!;
  }

  private loadUser(startStory?: Story, fromEnd = false): void {
    const user = this.user();
    if (!user) return this.attrs.close();

    this.loading = true;
    this.storiesOf(user).then((stories) => {
      if (this.user() !== user) return;
      this.loading = false;
      // Couldn't load (the alert said so): stop here rather than skip them as if they had nothing.
      if (stories === null) return this.attrs.close();
      if (!stories.length) {
        // Gone (expired, deleted) since the bar was drawn: on to the next person.
        this.users.splice(this.userIndex, 1);
        if (!this.users.length) return this.attrs.close();
        this.userIndex = Math.min(this.userIndex, this.users.length - 1);
        return this.loadUser();
      }
      this.stories = stories;
      const firstUnseen = stories.findIndex((story) => !story.isSeen());
      this.index = startStory ? Math.max(0, stories.indexOf(startStory)) : fromEnd ? stories.length - 1 : Math.max(0, firstUnseen);
      this.begin();
      m.redraw();
    });
  }

  private begin(): void {
    const story = this.story();
    if (!story) return;
    this.elapsed = 0;
    this.last = performance.now();
    this.menuOpen = false;
    this.reacting = false;
    this.paused = false;
    const slide = (Number(app.forum.attribute('glimpseSlideSeconds')) || 6) * 1000;
    const max = (Number(app.forum.attribute('glimpseVideoMaxSeconds')) || 15) * 1000;
    this.duration = story.kind() === 'video' ? Math.min(story.durationMs() || max, max) : slide;
    this.markSeen(story);
    this.prefetch();
  }

  private prefetch(): void {
    const next = this.stories[this.index + 1];
    if (next) return preload(next);
    const nextUser = this.users[this.userIndex + 1];
    if (nextUser) this.storiesOf(nextUser).then((stories) => stories?.[0] && preload(stories[0]));
  }

  private markSeen(story: Story): void {
    if (story.isSeen() || story.user() === app.session.user || !app.session.user) return;
    story.pushAttributes({ isSeen: true });
    this.seen.add(story.id()!);
    clearTimeout(this.seenTimer);
    this.seenTimer = window.setTimeout(() => this.flushSeen(), SEEN_FLUSH_MS);
  }

  private flushSeen(): void {
    clearTimeout(this.seenTimer);
    if (!this.seen.size) return;
    const ids = [...this.seen].map(Number);
    this.seen.clear();
    // The bar reloads after the viewer closes: it waits for this, so what was just seen is grey.
    // A send that fails goes back in the batch for the next flush (closing flushes once more).
    trackSeen(
      app
        .request({ method: 'POST', url: `${app.forum.attribute('apiUrl')}/glimpse-stories/seen`, body: { ids }, errorHandler: () => {} })
        .catch(() => ids.forEach((id) => this.seen.add(String(id))))
    );
  }

  // ---------- playback ----------

  private tick(now: number): void {
    const video = this.video();
    if (!this.paused && !this.loading && this.story()) {
      if (video && video.duration && isFinite(video.duration)) {
        this.duration = Math.min(video.duration * 1000, (Number(app.forum.attribute('glimpseVideoMaxSeconds')) || 15) * 1000);
        this.elapsed = video.currentTime * 1000;
      } else if (!video) {
        this.elapsed += now - this.last;
      }
      const bar = this.element?.querySelector<HTMLElement>('.GlimpseViewer-progress li.is-current b');
      if (bar) bar.style.width = `${Math.min(100, (this.elapsed / this.duration) * 100)}%`;
      if (this.elapsed >= this.duration) this.next();
    }
    this.last = now;
    this.raf = requestAnimationFrame((time) => this.tick(time));
  }

  private video(): HTMLVideoElement | null {
    return this.element?.querySelector<HTMLVideoElement>('.GlimpseViewer-media video') ?? null;
  }

  private setPaused(paused: boolean): void {
    this.paused = paused;
    this.last = performance.now();
    const video = this.video();
    if (video && paused) video.pause();
    if (video && !paused) {
      // A browser that won't autoplay leaves the story paused, with the play button showing.
      video.play().catch(() => {
        this.paused = true;
        m.redraw();
      });
    }
    m.redraw();
  }

  next(): void {
    if (this.index < this.stories.length - 1) {
      this.index++;
      this.begin();
      m.redraw();
    } else this.nextUser();
  }

  previous(): void {
    if (this.index > 0) {
      this.index--;
      this.begin();
      m.redraw();
    } else if (this.userIndex > 0) {
      this.userIndex--;
      this.loadUser(undefined, true);
    } else {
      this.begin();
    }
  }

  nextUser(): void {
    this.flushSeen();
    if (this.userIndex < this.users.length - 1) {
      this.userIndex++;
      this.loadUser();
    } else this.attrs.close();
  }

  previousUser(): void {
    if (this.userIndex > 0) {
      this.userIndex--;
      this.loadUser();
    } else {
      this.index = 0;
      this.begin();
    }
  }

  // ---------- input ----------

  private onkey(e: KeyboardEvent): void {
    keys(e, {
      forward: () => this.next(),
      back: () => this.previous(),
      pause: () => this.setPaused(!this.paused),
      escape: () => {
        if (!this.menuOpen) return this.attrs.close();
        this.menuOpen = false;
        this.setPaused(false);
      },
    });
  }

  private say(message: string): void {
    this.toast = message;
    clearTimeout(this.toastTimer);
    this.toastTimer = window.setTimeout(() => {
      this.toast = null;
      m.redraw();
    }, 1800);
    m.redraw();
  }

  // ---------- actions ----------

  private react(reaction: string | null): void {
    const story = this.story();
    if (!story) return;
    this.reacting = false;
    const previous = story.myReaction();
    story.pushAttributes({ myReaction: reaction });
    app
      .request<ApiPayloadSingle>({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/glimpse-stories/${story.id()}/reaction`,
        body: { reaction },
      })
      .then((payload) => {
        app.store.pushPayload(payload);
        this.say(reaction ? tx('viewer.reacted', { reaction }) : tx('viewer.unreacted'));
      })
      .catch(() => story.pushAttributes({ myReaction: previous }))
      .finally(() => this.setPaused(false));
  }

  // ---------- view ----------

  view() {
    const story = this.story();
    const user = this.user();

    return (
      <div
        className={classList('GlimpseViewer', this.paused && 'is-paused', this.gestures.holding && 'is-holding')}
        role="dialog"
        aria-modal="true"
        aria-label={tx('viewer.label', { name: user?.displayName() || '' })}
      >
        <button type="button" className="GlimpseViewer-close" aria-label={tx('viewer.close')} onclick={() => this.attrs.close()}>
          <i className="fas fa-xmark" aria-hidden="true" />
        </button>
        <button
          type="button"
          className="GlimpseViewer-arrow GlimpseViewer-arrow--prev"
          aria-label={tx('viewer.previous')}
          onclick={() => this.previous()}
        >
          <i className="fas fa-chevron-left" aria-hidden="true" />
        </button>
        {this.card(story, user)}
        <button type="button" className="GlimpseViewer-arrow GlimpseViewer-arrow--next" aria-label={tx('viewer.next')} onclick={() => this.next()}>
          <i className="fas fa-chevron-right" aria-hidden="true" />
        </button>
      </div>
    );
  }

  private card(story: Story | undefined, user: User | undefined): Mithril.Children {
    const own = !!story && story.user() === app.session.user;

    return (
      <div
        className="GlimpseViewer-card"
        onpointerdown={(e: PointerEvent) => this.gestures.down(e)}
        onpointermove={(e: PointerEvent) => this.gestures.move(e)}
        onpointerup={(e: PointerEvent) => this.gestures.up(e)}
        onpointercancel={() => this.gestures.cancel()}
        oncontextmenu={(e: Event) => e.preventDefault()}
      >
        {story && !this.loading ? (
          this.media(story)
        ) : (
          <div className="GlimpseViewer-media GlimpseViewer-media--loading">
            <LoadingIndicator />
          </div>
        )}
        {this.progressBars()}
        {story && user ? this.header(story, user, own) : null}
        {this.menuOpen && story ? (
          <ViewerMenu
            story={story}
            own={own}
            onsay={(message: string) => this.say(message)}
            onclose={(resume: boolean) => {
              this.menuOpen = false;
              if (resume) this.setPaused(false);
            }}
            onmuted={() => this.nextUser()}
            ondeleted={() => this.dropCurrent()}
          />
        ) : null}
        {this.paused && this.gestures.holding ? <div className="GlimpseViewer-paused">{t('viewer.paused')}</div> : null}
        {this.toast ? (
          <div className="GlimpseViewer-toast" role="status">
            {this.toast}
          </div>
        ) : null}
        {story ? this.footer(story, user, own) : null}
        {this.nextPreload()}
      </div>
    );
  }

  private progressBars(): Mithril.Children {
    return (
      <ol className="GlimpseViewer-progress" aria-hidden="true">
        {this.stories.map((_, i) => (
          <li className={classList(i < this.index && 'is-done', i === this.index && 'is-current')}>
            <b />
          </li>
        ))}
      </ol>
    );
  }

  private header(story: Story, user: User, own: boolean): Mithril.Children {
    return (
      <header className="GlimpseViewer-head">
        <Link href={app.route.user(user)} className="GlimpseViewer-author" onclick={() => this.attrs.close()}>
          <Avatar user={user} />
          <span className="GlimpseViewer-name">{own ? t('viewer.your_story') : user.displayName()}</span>
        </Link>
        <span className="GlimpseViewer-time">{this.attrs.options.label || humanTime(story.createdAt())}</span>
        <i
          className={classList('GlimpseViewer-audience fas', AUDIENCE_ICONS[story.audience()])}
          title={tx(`audience.${story.audience()}`)}
          aria-label={tx(`audience.${story.audience()}`)}
        />
        <span className="GlimpseViewer-spacer" />
        {story.kind() === 'video' ? (
          <button
            type="button"
            className="GlimpseViewer-icon"
            aria-label={tx(this.muted ? 'viewer.unmute' : 'viewer.mute')}
            onclick={() => this.toggleSound()}
          >
            <i className={classList('fas', this.muted ? 'fa-volume-xmark' : 'fa-volume-high')} aria-hidden="true" />
          </button>
        ) : null}
        <button
          type="button"
          className="GlimpseViewer-icon"
          aria-label={tx(this.paused ? 'viewer.play' : 'viewer.pause')}
          onclick={() => this.setPaused(!this.paused)}
        >
          <i className={classList('fas', this.paused ? 'fa-play' : 'fa-pause')} aria-hidden="true" />
        </button>
        <button
          type="button"
          className="GlimpseViewer-icon"
          aria-label={tx('viewer.more')}
          aria-expanded={this.menuOpen ? 'true' : 'false'}
          onclick={() => {
            this.menuOpen = !this.menuOpen;
            this.setPaused(this.menuOpen);
          }}
        >
          <i className="fas fa-ellipsis" aria-hidden="true" />
        </button>
      </header>
    );
  }

  private footer(story: Story, user: User | undefined, own: boolean): Mithril.Children {
    return (
      <footer className="GlimpseViewer-foot">
        {story.caption() ? <p className="GlimpseViewer-caption">{caption(story)}</p> : null}
        {story.linkUrl() ? (
          <a className="GlimpseViewer-link" href={story.linkUrl()!} target="_blank" rel="nofollow ugc noopener noreferrer">
            <i className="fas fa-link" aria-hidden="true" /> {hostOf(story.linkUrl()!)}
          </a>
        ) : null}
        {this.reacting ? this.reactionPicker(story) : null}
        {own ? (
          <button type="button" className="GlimpseViewer-seen" onclick={() => this.openSeenBy(story)}>
            <i className="fas fa-eye" aria-hidden="true" /> {t('viewer.seen_by', { count: story.viewCount() || 0 })}
            {story.reactionCount() ? (
              <span className="GlimpseViewer-seenReactions"> · {t('viewer.reactions', { count: story.reactionCount() })}</span>
            ) : null}
          </button>
        ) : (
          this.replyRow(story, user)
        )}
      </footer>
    );
  }

  /** The reply field (when replies have somewhere to go) and the reaction button. */
  private replyRow(story: Story, user: User | undefined): Mithril.Children {
    const reactions = this.reactions();

    return (
      <ReplyBar
        story={story}
        name={user?.displayName() || ''}
        reaction={story.myReaction() || reactions[0]}
        reacting={this.reacting}
        onpause={(paused: boolean) => this.setPaused(paused)}
        onsay={(message: string) => this.say(message)}
        onreact={() => {
          if (reactions.length === 1) return this.react(story.myReaction() ? null : reactions[0]);
          this.reacting = !this.reacting;
          this.setPaused(this.reacting);
        }}
      />
    );
  }

  private reactionPicker(story: Story): Mithril.Children {
    return (
      <div className="GlimpseViewer-reactions" role="group" aria-label={tx('viewer.react')}>
        {this.reactions().map((reaction) => (
          <button
            type="button"
            className={classList(story.myReaction() === reaction && 'is-active')}
            aria-pressed={story.myReaction() === reaction ? 'true' : 'false'}
            onclick={() => this.react(story.myReaction() === reaction ? null : reaction)}
          >
            {reaction}
          </button>
        ))}
      </div>
    );
  }

  private reactions(): string[] {
    const list = app.forum.attribute<string[]>('glimpseReactions');
    return list?.length ? list : ['❤️'];
  }

  private media(story: Story): Mithril.Children {
    if (story.kind() === 'text') {
      const text = story.text() || '';
      return (
        <div className={classList('GlimpseViewer-media GlimpseText', `GlimpseBg-${story.background() ?? 0}`)}>
          <p className={classList('GlimpseText-body', text.length > 90 && 'is-long')}>{text}</p>
        </div>
      );
    }

    return (
      <div className="GlimpseViewer-media">
        {story.kind() === 'video' ? (
          <video
            key={story.id()}
            src={story.mediaUrl()!}
            autoplay
            playsinline
            muted={this.muted}
            preload="auto"
            onended={() => this.next()}
            onloadeddata={(e: Event) => {
              if (this.paused) (e.target as HTMLVideoElement).pause();
            }}
          />
        ) : (
          <img
            key={story.id()}
            src={story.mediaUrl()!}
            alt={story.caption() || tx('viewer.photo_alt', { name: (story.user() || null)?.displayName() })}
            draggable={false}
          />
        )}
      </div>
    );
  }

  /** The next story's media, fetched while this one plays. */
  private nextPreload(): Mithril.Children {
    const next = this.stories[this.index + 1];
    if (!next || next.kind() !== 'video' || !next.mediaUrl()) return null;
    return <video className="GlimpseViewer-preload" src={next.mediaUrl()!} preload="auto" muted playsinline aria-hidden="true" />;
  }

  /** The current story was deleted: on to the next one, or the next person. */
  private dropCurrent(): void {
    const user = this.user();
    this.stories.splice(this.index, 1);
    if (user) this.cache.delete(user.id()!);
    if (!this.stories.length) return this.nextUser();
    this.index = Math.min(this.index, this.stories.length - 1);
    this.begin();
  }

  private toggleSound(): void {
    this.muted = !this.muted;
    const video = this.video();
    if (video) video.muted = this.muted;
  }

  private openSeenBy(story: Story): void {
    this.setPaused(true);
    openSeenBy(story, () => this.setPaused(false));
  }
}
