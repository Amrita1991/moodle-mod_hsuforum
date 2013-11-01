/**
 * DOM Updater
 *
 * @module moodle-mod_hsuforum-dom
 */

/**
 * Handles updating forum DOM structures.
 *
 * @constructor
 * @namespace M.mod_hsuforum
 * @class Dom
 * @extends Y.Base
 */
function DOM() {
    DOM.superclass.constructor.apply(this, arguments);
}

DOM.NAME = 'moodle-mod_hsuforum-dom';

DOM.ATTRS = {
    /**
     * Used for requests
     *
     * @attribute io
     * @type M.mod_hsuforum.Io
     * @required
     */
    io: { value: null }
};

Y.extend(DOM, Y.Base,
    {
        initializer: function() {
            // Any ratings initially on the page will already be processed.
            Y.all(SELECTORS.RATE).addClass('processed');
            // Initialize current menu options.
            this.initOptionMenus();
        },

        /**
         * Initialize thread JS features that are not handled by
         * delegates.
         */
        initFeatures: function() {
            this.initOptionMenus();
            this.initRatings();
        },

        /**
         * Wire up ratings that have been dynamically added to the page.
         */
        initRatings: function() {
            Y.all(SELECTORS.RATE).each(function(node) {
                if (node.hasClass('processed')) {
                    return;
                }
                M.core_rating.Y = Y;
                node.all('select.postratingmenu').each(M.core_rating.attach_rating_events, M.core_rating);
                node.all('input.postratingmenusubmit').setStyle('display', 'none');
                node.addClass('processed');
            });
        },

        /**
         * Initialize option menus.
         */
        initOptionMenus: function() {
            Y.all(SELECTORS.OPTIONS_TO_PROCESS).each(function(node) {
                node.removeClass('unprocessed');

                var menu = new Y.YUI2.widget.Menu(node.generateID(), { lazyLoad: true });

                // Render to container otherwise tool region gets wonky huge!
                menu.render(Y.one(SELECTORS.CONTAINER).generateID());

                Y.one('#' + node.getData('controller')).on('click', function(e) {
                    e.preventDefault();
                    menu.cfg.setProperty('y', e.currentTarget.getY() + e.currentTarget.get('offsetHeight'));
                    menu.cfg.setProperty('x', e.currentTarget.getX());
                    menu.show();
                });
            });
        },

        /**
         * For dynamically loaded ratings, we need to handle the view
         * ratings pop-up manually.
         *
         * @param e
         */
        handleViewRating: function(e) {
            if (e.currentTarget.ancestor('.helplink') !== null) {
                return; // Ignore help link.
            }
            e.preventDefault();
            openpopup(e, {
                url: e.currentTarget.get('href') + '&popup=1',
                name: 'ratings',
                options: "height=400,width=600,top=0,left=0,menubar=0,location=0," +
                    "scrollbars,resizable,toolbar,status,directories=0,fullscreen=0,dependent"
            });
        },

        /**
         * @param {Integer} postid
         * @param {Function} fn
         * @param {Object} context Specifies what 'this' refers to.
         */
        markPostAsRead: function(postid, fn, context) {
            this.get('io').send({
                postid: postid,
                action: 'markread'
            }, fn, context);
        },

        /**
         * @param node
         * @param {Function} fn
         * @param {Object} context Specifies what 'this' refers to.
         */
        ensurePostsExist: function(node, fn, context) {
            var unread = node.hasAttribute('data-isunread');
            if (unread) {
                node.removeAttribute('data-isunread');
            }
            var viewNode = node.one(SELECTORS.PLACEHOLDER);
            if (viewNode === null) {
                this.initFeatures();
                if (unread) {
                    this.markPostAsRead(node.getData('postid'), fn, context);
                } else {
                    fn.call(context);
                }
                return;
            }
            Y.log('Fetching posts for discussion: ' + node.getData('discussionid'), 'info', 'Dom');

            this.get('io').send({
                discussionid: node.getData('discussionid'),
                action: 'posts_html'
            }, function(data) {
                viewNode.replace(data.html);
                this.initFeatures();
                fn.call(context);
            }, this);
        },

        /**
         * Display a notification
         * @param {String} html
         */
        displayNotification: function(html) {
            var node = Y.Node.create(html);
            Y.one(SELECTORS.NOTIFICATION).append(node);

            setTimeout(function() {
                node.remove(true);
            }, 10000);
        },

        /**
         * Post created event handler
         *
         * Grab HTML from the event and insert it.
         *
         * @param e
         */
        handlePostCreated: function (e) {
            Y.log('Post created, updating HTML for discussion: ' + e.discussionid, 'info', 'Dom');
            var node = Y.one(SELECTORS.DISCUSSION_BY_ID.replace('%d', e.discussionid));
            node.replace(e.html);
        },

        /**
         * Discussion created event handler
         *
         * Grab HTML from the event and insert it.
         * Also update discussion count.
         *
         * @param e
         */
        handleDiscussionCreated: function(e) {
            Y.log('Adding HTML for discussion: ' + e.discussionid, 'info', 'Dom');
            this.displayNotification(e.notificationhtml);

            // Update number of discussions.
            var countNode = Y.one(SELECTORS.DISCUSSION_COUNT);
            if (countNode !== null) {
                // Increment the count and update display.
                countNode.setData('count', parseInt(countNode.getData('count'), 10) + 1);
                countNode.setHTML(M.util.get_string('xdiscussions', 'mod_hsuforum', countNode.getData('count')));
            }
            Y.one(SELECTORS.ADD_DISCUSSION_BUTTON).focus();
        },

        /**
         * Delete post and update view
         *
         * @method handlePostDelete
         * @param e
         */
        handlePostDelete: function(e) {
            var node = Y.one(SELECTORS.POST_BY_ID.replace('%d', e.postid));
            if (node === null) {
                return;
            }
            Y.log('Deleting post: ' + e.postid);

            this.get('io').send({
                postid: e.postid,
                sesskey: M.cfg.sesskey,
                action: 'delete_post'
            }, function(data) {
                if (node.hasAttribute('data-isdiscussion')) {
                    // Redirect for now because discussions need to be re-rendered due to navigation.
                    window.location.href = data.redirecturl;
                } else {
                    var discNode = Y.one(SELECTORS.DISCUSSION_BY_ID.replace('%d', node.getData('discussionid')));
                    discNode.replace(data.html);
                    this.fire(EVENTS.POST_DELETED, data);
                }
            }, this);
        },

        /**
         * Load more discussions onto the page
         *
         * @param {Integer} page
         * @param {Integer} perpage
         * @param {Function} fn
         * @param context
         */
        loadMoreDiscussions: function(page, perpage, fn, context) {
            var node = Y.one(SELECTORS.LOAD_TARGET);

            if (!(node instanceof Y.Node)) {
                Y.log('Not fetching more discussions because discussion wrapper node not found', 'error', 'Dom');
                return;
            }
            Y.log('Fetching ' + perpage + ' discussions for page ' + page, 'info', 'Dom');

            this.get('io').send({
                page: page,
                perpage: perpage,
                action: 'discussions_html'
            }, function(data) {
                node.insert(data.html, 'before');
                fn.call(context);
            }, this);
        }
    }
);

M.mod_hsuforum.Dom = DOM;
