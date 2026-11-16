import Model from 'flarum/common/Model';
import type User from 'flarum/common/models/User';

export type Kind = 'photo' | 'video' | 'text';
export type Audience = 'everyone' | 'members' | 'followers';

/** A story, as Api\StoryResource serializes it for the person looking. */
export default class Story extends Model {
  kind = Model.attribute<Kind>('kind');
  text = Model.attribute<string | null>('text');
  background = Model.attribute<number | null>('background');
  caption = Model.attribute<string | null>('caption');
  linkUrl = Model.attribute<string | null>('linkUrl');
  audience = Model.attribute<Audience>('audience');
  status = Model.attribute<'pending' | 'live' | 'hidden'>('status');
  createdAt = Model.attribute<Date, string>('createdAt', Model.transformDate);
  expiresAt = Model.attribute<Date | null, string | null>('expiresAt', Model.transformDate);

  mediaUrl = Model.attribute<string | null>('mediaUrl');
  mediaType = Model.attribute<string | null>('mediaType');
  width = Model.attribute<number | null>('width');
  height = Model.attribute<number | null>('height');
  durationMs = Model.attribute<number | null>('durationMs');

  isSeen = Model.attribute<boolean>('isSeen');
  myReaction = Model.attribute<string | null>('myReaction');
  authorFollowed = Model.attribute<boolean>('authorFollowed');
  /** The ring bar only: whether the author has a live story the viewer hasn't seen. */
  authorHasUnseen = Model.attribute<boolean | undefined>('authorHasUnseen');
  viewCount = Model.attribute<number | undefined>('viewCount');
  reactionCount = Model.attribute<number | undefined>('reactionCount');

  canDelete = Model.attribute<boolean>('canDelete');
  canModerate = Model.attribute<boolean>('canModerate');
  canReact = Model.attribute<boolean>('canReact');
  canReport = Model.attribute<boolean>('canReport');
  canSeeViewers = Model.attribute<boolean>('canSeeViewers');

  user = Model.hasOne<User>('user');
  mentionedUsers = Model.hasMany<User>('mentionedUsers');
}
