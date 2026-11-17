import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';
import type ExtensionPage from 'flarum/admin/components/ExtensionPage';
import { has } from '../common/runtime';

const EXT = 'ernestdefoe-glimpse';
const t = (key: string) => app.translator.trans(`${EXT}.admin.${key}`, {}, true);
const setting = (key: string) => `${EXT}.${key}`;
const installed = has;

const options = (keys: string[], prefix: string) => Object.fromEntries(keys.map((key) => [key, t(`${prefix}.${key}`)]));

export default [
  new Extend.Admin()
    .setting(
      () => ({
        setting: setting('lifetime_hours'),
        type: 'select',
        label: t('lifetime'),
        help: t('lifetime_help'),
        // String keys: integer-like keys would be re-sorted by JS.
        options: { '6': t('hours.6'), '12': t('hours.12'), '24': t('hours.24'), '48': t('hours.48'), '168': t('hours.168') },
        default: '24',
      }),
      100
    )
    .setting(() => ({ setting: setting('kind_photo'), type: 'boolean', label: t('kind_photo'), help: t('kind_photo_help') }), 95)
    .setting(() => ({ setting: setting('kind_video'), type: 'boolean', label: t('kind_video'), help: t('kind_video_help') }), 94)
    .setting(() => ({ setting: setting('kind_text'), type: 'boolean', label: t('kind_text') }), 93)
    .setting(() => ({ setting: setting('image_max_mb'), type: 'number', min: 1, max: 50, label: t('image_max_mb') }), 90)
    .setting(() => ({ setting: setting('video_max_mb'), type: 'number', min: 1, max: 200, label: t('video_max_mb') }), 89)
    .setting(() => ({ setting: setting('video_max_seconds'), type: 'number', min: 3, max: 60, label: t('video_max_seconds') }), 88)
    .setting(
      () => ({ setting: setting('slide_seconds'), type: 'number', min: 2, max: 20, label: t('slide_seconds'), help: t('slide_seconds_help') }),
      87
    )
    .setting(
      () => ({
        setting: setting('bar_placement'),
        type: 'select',
        label: t('bar_placement'),
        options: options(['index', 'index_tags', 'page', 'none'], 'placement'),
        default: 'index',
      }),
      80
    )
    .setting(
      () => ({
        setting: setting('bar_order'),
        type: 'select',
        label: t('bar_order'),
        help: t('bar_order_help'),
        options: options(['smart', 'newest'], 'order'),
        default: 'smart',
      }),
      79
    )
    .setting(
      () => ({
        setting: setting('default_audience'),
        type: 'select',
        label: t('default_audience'),
        options: options(installed('ianm-follow-users') ? ['everyone', 'members', 'followers'] : ['everyone', 'members'], 'audience'),
        default: 'everyone',
      }),
      70
    )
    .customSetting(function (this: ExtensionPage) {
      if (!installed('fof-ignore-users')) return null;
      return this.buildSettingComponent({
        setting: setting('ignore_hides'),
        type: 'boolean',
        label: t('ignore_hides'),
        help: t('ignore_hides_help'),
      });
    }, 69)
    .setting(() => ({ setting: setting('reactions'), type: 'text', label: t('reactions'), help: t('reactions_help') }), 60)
    .customSetting(function (this: ExtensionPage) {
      const channels = ['auto', ...(installed('ernestdefoe-parley') ? ['parley'] : []), ...(installed('flarum-messages') ? ['messages'] : []), 'off'];
      return this.buildSettingComponent({
        setting: setting('reply_via'),
        type: 'select',
        label: t('reply_via'),
        help: t('reply_via_help'),
        options: options(channels, 'reply'),
        default: 'auto',
      });
    }, 59)
    .setting(() => ({ setting: setting('approval_posts'), type: 'number', min: 0, label: t('approval_posts'), help: t('approval_help') }), 50)
    .setting(() => ({ setting: setting('approval_days'), type: 'number', min: 0, label: t('approval_days') }), 49)
    .customSetting(function (this: ExtensionPage) {
      if (!installed('ernestdefoe-trust-levels')) return null;
      return this.buildSettingComponent({
        setting: setting('approval_trust_level'),
        type: 'number',
        min: 0,
        max: 4,
        label: t('approval_trust_level'),
      });
    }, 48)
    .customSetting(function (this: ExtensionPage) {
      if (!installed('ianm-follow-users')) return null;
      return [
        this.buildSettingComponent({
          setting: setting('notify_followers'),
          type: 'boolean',
          label: t('notify_followers'),
          help: t('notify_followers_help'),
        }),
        this.buildSettingComponent({ setting: setting('notify_cap'), type: 'number', min: 1, max: 5000, label: t('notify_cap') }),
      ];
    }, 40)
    .setting(() => ({ setting: setting('daily_limit'), type: 'number', min: 1, max: 200, label: t('daily_limit') }), 30)
    .setting(() => ({ setting: setting('highlights_max'), type: 'number', min: 1, max: 100, label: t('highlights_max') }), 29)
    .setting(() => ({ setting: setting('highlight_stories_max'), type: 'number', min: 1, max: 500, label: t('highlight_stories_max') }), 28)
    .permission(() => ({ permission: 'glimpse.view', icon: 'fas fa-circle-play', label: t('permissions.view'), allowGuest: true }), 'view', 50)
    .permission(() => ({ permission: 'glimpse.create', icon: 'fas fa-circle-plus', label: t('permissions.create') }), 'start', 50)
    .permission(() => ({ permission: 'glimpse.react', icon: 'fas fa-heart', label: t('permissions.react') }), 'reply', 50)
    .permission(() => ({ permission: 'glimpse.skipApproval', icon: 'fas fa-check-double', label: t('permissions.skip_approval') }), 'start', 49)
    .permission(() => ({ permission: 'glimpse.moderate', icon: 'fas fa-circle-play', label: t('permissions.moderate') }), 'moderate', 50),
];
