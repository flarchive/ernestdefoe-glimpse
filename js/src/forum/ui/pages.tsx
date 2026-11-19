import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import humanTime from 'flarum/common/helpers/humanTime';
import type User from 'flarum/common/models/User';
import type UserPageComponent from 'flarum/forum/components/UserPage';
import type { IUserPageAttrs } from 'flarum/forum/components/UserPage';
import { defaultExport, runtime } from '../../common/runtime';
import type Mithril from 'mithril';
import type Story from '../models/Story';
import type StoryReport from '../models/StoryReport';
import { failed, onStoriesChanged, storiesChanged, t, tx } from '../util';
import { IndexSidebar, PageStructure } from './core';
import { openViewer } from './mount';
import { openCreate } from './open';
import ProfileStories from './ProfileStories';
import { storyCard } from './card';

const PAGE = 24;

/** Plays the given stories grouped by author, starting at one of them. */
function play(stories: Story[], start: Story): void {
  const users: User[] = [];
  stories.forEach((story) => {
    const user = story.user();
    if (user && !users.includes(user)) users.push(user);
  });
  const author = start.user();
  if (author) openViewer({ users, start: author, startStory: start });
}

/** `/glimpse` (and `/glimpse/:id`, which opens that story over it): every live story you can see. */
export class StoriesPage extends Page {
  private stories: Story[] = [];
  private loading = true;
  private more = false;
  private following = false;
  private unlisten: (() => void) | null = null;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    app.setTitle(tx('page.title'));
    this.bodyClass = 'GlimpsePage-body';
    this.load(0);
    this.unlisten = onStoriesChanged(() => this.load(0));

    const id = m.route.param('id');
    if (id) {
      app.store
        .find<Story>('glimpse-stories', id)
        .then((story) => {
          const user = (story as Story).user();
          if (user)
            openViewer({
              users: [user],
              start: user,
              startStory: story as Story,
              onclose: () => m.route.set(app.route('glimpse.stories'), null, { replace: true }),
            });
        })
        .catch(() => app.alerts.show({ type: 'error' }, tx('page.gone')));
    }
  }

  onremove(vnode: Mithril.VnodeDOM) {
    super.onremove(vnode);
    this.unlisten?.();
  }

  load(offset: number): void {
    this.loading = true;
    const filter: Record<string, string> = this.following ? { live: '1', following: '1' } : { live: '1' };
    app.store
      .find<Story[]>('glimpse-stories', { filter, page: { offset, limit: PAGE, total: 0 } })
      .then((stories) => {
        this.stories = offset ? [...this.stories, ...stories] : [...stories];
        this.more = !!stories.payload?.links?.next;
      })
      .catch(failed)
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    return (
      <PageStructure className="GlimpsePage" sidebar={() => <IndexSidebar />}>
        <header className="GlimpsePage-head">
          <div>
            <h1 className="GlimpsePage-title">{t('page.title')}</h1>
            <p className="GlimpsePage-intro">{t('page.intro', { count: app.forum.attribute('glimpseLifetimeHours') })}</p>
          </div>
          {app.forum.attribute('glimpseCanCreate') ? (
            <Button className="Button Button--primary" icon="fas fa-plus" onclick={() => openCreate()}>
              {t('page.add')}
            </Button>
          ) : null}
        </header>
        {app.forum.attribute('glimpseFollows') && app.session.user ? (
          <div className="GlimpsePage-chips" role="tablist">
            {[false, true].map((following) => (
              <button
                type="button"
                role="tab"
                aria-selected={this.following === following ? 'true' : 'false'}
                className={classList('GlimpseChip', this.following === following && 'is-active')}
                onclick={() => {
                  this.following = following;
                  this.load(0);
                }}
              >
                {t(following ? 'page.following' : 'page.all')}
              </button>
            ))}
          </div>
        ) : null}
        {this.stories.length ? (
          <div className="GlimpseGrid">{this.stories.map((story) => storyCard(story, () => play(this.stories, story)))}</div>
        ) : this.loading ? null : (
          <p className="GlimpsePage-empty">{t('page.empty')}</p>
        )}
        {this.loading ? <LoadingIndicator /> : null}
        {this.more && !this.loading ? (
          <div className="GlimpsePage-more">
            <Button className="Button" onclick={() => this.load(this.stories.length)}>
              {t('page.load_more')}
            </Button>
          </div>
        ) : null}
      </PageStructure>
    );
  }
}

/** `/glimpse/queue`: stories waiting for approval, and reported ones. Moderators only (the server checks). */
export class QueuePage extends Page {
  private tab: 'pending' | 'reported' = 'pending';
  private stories: Story[] = [];
  private reports: StoryReport[] = [];
  private loading = true;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    app.setTitle(tx('queue.title'));
    this.load();
  }

  load(): void {
    this.loading = true;
    const request =
      this.tab === 'pending'
        ? app.store
            .find<Story[]>('glimpse-stories', { filter: { status: 'pending' }, page: { limit: 50, total: 0 } })
            .then((s) => (this.stories = [...(s as Story[])]))
        : app.store
            .find<StoryReport[]>('glimpse-reports', { include: 'story,story.user,user', page: { limit: 50, total: 0 } })
            .then((r) => (this.reports = [...(r as StoryReport[])]));
    request.catch(failed).finally(() => {
      this.loading = false;
      m.redraw();
    });
  }

  view() {
    return (
      <PageStructure className="GlimpsePage GlimpseQueue" sidebar={() => <IndexSidebar />}>
        <header className="GlimpsePage-head">
          <h1 className="GlimpsePage-title">{t('queue.title')}</h1>
        </header>
        <div className="GlimpsePage-chips" role="tablist">
          {(['pending', 'reported'] as const).map((tab) => (
            <button
              type="button"
              role="tab"
              aria-selected={this.tab === tab ? 'true' : 'false'}
              className={classList('GlimpseChip', this.tab === tab && 'is-active')}
              onclick={() => {
                this.tab = tab;
                this.load();
              }}
            >
              {t(`queue.${tab}`)}
            </button>
          ))}
        </div>
        {this.loading ? <LoadingIndicator /> : this.tab === 'pending' ? this.pending() : this.reported()}
      </PageStructure>
    );
  }

  private pending(): Mithril.Children {
    if (!this.stories.length) return <p className="GlimpsePage-empty">{t('queue.pending_empty')}</p>;

    return (
      <div className="GlimpseGrid">
        {this.stories.map((story) =>
          storyCard(story, () => play(this.stories, story), [
            <Button className="Button Button--primary" icon="fas fa-check" onclick={() => this.decide(story, 'live')}>
              {t('queue.approve')}
            </Button>,
            <Button className="Button" icon="fas fa-eye-slash" onclick={() => this.decide(story, 'hidden')}>
              {t('queue.decline')}
            </Button>,
          ])
        )}
      </div>
    );
  }

  private reported(): Mithril.Children {
    if (!this.reports.length) return <p className="GlimpsePage-empty">{t('queue.reported_empty')}</p>;

    return (
      <ul className="GlimpseReports">
        {this.reports.map((report) => {
          const story = report.story() || null;
          const reporter = report.user();
          return (
            <li className="GlimpseReports-item">
              {story && story.user()
                ? storyCard(story, () => openViewer({ users: [story.user() as User], start: story.user() as User, stories: [story] }))
                : null}
              <div className="GlimpseReports-body">
                <p className="GlimpseReports-reason">
                  <strong>{t(`report.reasons.${report.reason()}`)}</strong>
                  {' · '}
                  {reporter ? reporter.displayName() : null} {humanTime(report.createdAt())}
                </p>
                {report.detail() ? <p className="GlimpseReports-detail">{report.detail()}</p> : null}
                <div className="GlimpseReports-actions">
                  <Button className="Button" icon="fas fa-check" onclick={() => this.dismiss(report)}>
                    {t('queue.dismiss')}
                  </Button>
                  {story ? (
                    <Button className="Button" icon="fas fa-eye-slash" onclick={() => this.decide(story, 'hidden').then(() => this.dismiss(report))}>
                      {t('queue.hide')}
                    </Button>
                  ) : null}
                  {story?.canDelete() ? (
                    <Button
                      className="Button Button--danger"
                      icon="fas fa-trash"
                      onclick={() => {
                        if (confirm(tx('viewer.delete_confirm'))) story.delete().then(() => this.load());
                      }}
                    >
                      {t('queue.delete')}
                    </Button>
                  ) : null}
                </div>
              </div>
            </li>
          );
        })}
      </ul>
    );
  }

  private decide(story: Story, status: 'live' | 'hidden'): Promise<void> {
    return story.save({ status }).then(() => {
      this.stories = this.stories.filter((s) => s !== story);
      const pending = Number(app.forum.attribute('glimpsePending') || 0);
      app.forum.pushAttributes({ glimpsePending: Math.max(0, pending - 1) });
      storiesChanged();
      m.redraw();
    });
  }

  private dismiss(report: StoryReport): void {
    report.delete().then(() => {
      this.reports = this.reports.filter((r) => r !== report);
      m.redraw();
    });
  }
}

let made: unknown = null;

/**
 * The profile tab. UserPage lives in core's lazy chunk, so this small subclass is made once it has
 * loaded; the tab itself is ProfileStories.
 */
export async function userStoriesPage(): Promise<unknown> {
  if (made) return made;
  const UserPage = defaultExport<typeof UserPageComponent>(await runtime()!.reg.asyncModuleImport('flarum/forum/components/UserPage'));

  made = class UserStoriesPage extends UserPage {
    oninit(vnode: Mithril.Vnode<IUserPageAttrs, this>) {
      super.oninit(vnode);
      this.loadUser(m.route.param('username'));
    }

    content() {
      return this.user ? <ProfileStories key={this.user.id()} user={this.user} /> : null;
    }
  };

  return made;
}
