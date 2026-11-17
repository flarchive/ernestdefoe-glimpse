import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import LinkButton from 'flarum/common/components/LinkButton';
import type ItemList from 'flarum/common/utils/ItemList';
import type UserPage from 'flarum/forum/components/UserPage';
import type Mithril from 'mithril';
import RingBar from './RingBar';
import { registerWidgets } from './widgets';
import { t } from './util';
import extenders, { NOTIFICATIONS } from './extend';

export { extenders as extend };

export { default as RingBar } from './RingBar';
export { default as Story } from './models/Story';

/*
 * forum.js carries the models, the ring bar and the wiring. The viewer, the create sheet, the pages and
 * the modals are one lazy chunk (./ui), fetched the first time someone opens a story.
 */

/** Whether the ring bar belongs on this discussion list, by the admin's placement setting. */
function barHere(): boolean {
  if (!app.forum.attribute('glimpseCanView')) return false;
  const placement = app.forum.attribute<string>('glimpseBarPlacement');
  if (placement === 'index_tags') return true;
  return placement === 'index' && !m.route.param('tags');
}

app.initializers.add('ernestdefoe-glimpse', () => {
  registerWidgets();

  extend('flarum/forum/components/IndexPage', 'contentItems', function (items: ItemList<Mithril.Children>) {
    if (barHere()) items.add('glimpseBar', <RingBar />, 110);
  });

  extend('flarum/forum/components/IndexSidebar', 'navItems', function (items: ItemList<Mithril.Children>) {
    if (!app.forum.attribute('glimpseCanView')) return;
    items.add(
      'glimpse',
      <LinkButton href={app.route('glimpse.stories')} icon="fas fa-circle-play">
        {t('nav.stories')}
      </LinkButton>,
      74
    );

    if (app.forum.attribute('glimpseCanModerate')) {
      const pending = Number(app.forum.attribute('glimpsePending') || 0);
      items.add(
        'glimpseQueue',
        // The count is drawn by CSS from an attribute: SelectDropdown repeats the active item's label,
        // and one vnode in two places throws when it changes.
        <LinkButton
          href={app.route('glimpse.queue')}
          icon="fas fa-inbox"
          className="GlimpseNav-queue"
          data-count={pending ? (pending > 99 ? '99+' : String(pending)) : undefined}
        >
          {t('nav.queue')}
        </LinkButton>,
        73
      );
    }
  });

  extend('flarum/forum/components/UserPage', 'navItems', function (this: UserPage, items: ItemList<Mithril.Children>) {
    if (!this.user || !app.forum.attribute('glimpseCanView')) return;
    items.add(
      'glimpse',
      <LinkButton href={app.route('user.glimpse', { username: this.user.slug() })} icon="fas fa-circle-play">
        {t('nav.stories')}
      </LinkButton>,
      85
    );
  });

  extend(
    'flarum/forum/components/NotificationGrid',
    'notificationTypes',
    function (items: ItemList<{ name: string; icon: string; label: Mithril.Children }>) {
      Object.entries(NOTIFICATIONS).forEach(([name, icon]) => items.add(name, { name, icon, label: t(`notifications.${name}_preference`) }));
    }
  );
});
