(function($) {
    'use strict';

    var JASE = {
        currentStep: 1,
        completedSteps: [],
        selectedKeywords: [],
        selectedQuestions: [],
        gscQueries: [],
        selectedGscQueries: [],
        allKeywords: [],

        init: function() {
            this.bindEvents();
            this.initAccordions();

            // If already connected to GSC
            if (jaseAdmin.isConnected) {
                $('#jase-gsc-not-connected').hide();
                $('#jase-gsc-connected').show();
                if (jaseAdmin.siteUrl) {
                    $('#jase-site-selected').show().find('#jase-selected-site-url').text(jaseAdmin.siteUrl);
                    $('#jase-query-filters').show();
                }
            }

            // Check for GSC callback
            if (window.location.search.indexOf('gsc_connected=1') !== -1) {
                $('#jase-gsc-not-connected').hide();
                $('#jase-gsc-connected').show();
                this.loadGSCSites();
            }
        },

        bindEvents: function() {
            var self = this;

            // Accordion headers
            $(document).on('click', '.jase-accordion-header', function() {
                var step = parseInt($(this).data('step'));
                var accordion = $(this).closest('.jase-accordion');
                if (accordion.hasClass('is-disabled')) return;
                self.toggleStep(step);
            });

            // Next step buttons
            $(document).on('click', '.jase-next-step', function() {
                var next = parseInt($(this).data('next'));
                self.completeStep(self.currentStep);
                self.openStep(next);
            });

            // Step 1: GSC
            $('#jase-connect-gsc').on('click', function() { self.connectGSC(); });
            $('#jase-select-site').on('click', function() { self.selectGSCSite(); });
            $('#jase-fetch-queries').on('click', function() { self.fetchQueries(); });

            // GSC query selection
            $(document).on('change', '.jase-query-checkbox', function() {
                self.toggleGscQuery($(this));
            });
            $('#jase-query-select-all').on('change', function() {
                var checked = $(this).is(':checked');
                $('.jase-query-checkbox').each(function() {
                    if ($(this).is(':checked') !== checked) {
                        $(this).prop('checked', checked).trigger('change');
                    }
                });
            });

            // Step 2: Keywords
            $('#jase-start-keyword-research').on('click', function() { self.startKeywordResearch(); });
            $('#jase-confirm-keywords').on('click', function() { self.confirmKeywords(); });

            // Step 3: Questions
            $('#jase-fetch-questions').on('click', function() { self.fetchQuestions(); });
            $('#jase-confirm-questions').on('click', function() { self.confirmQuestions(); });

            // Step 4: Sitemap
            $('#jase-validate-sitemap').on('click', function() { self.validateSitemap(); });

            // Step 6: Generate
            $('#jase-generate-content').on('click', function() { self.generateContent(); });

            // Card selection
            $(document).on('click', '.jase-card[data-type="keyword"]', function() {
                self.toggleKeywordCard($(this));
            });
            $(document).on('click', '.jase-card[data-type="question"]', function() {
                self.toggleQuestionCard($(this));
            });

            // Settings save
            $('#jase-save-settings').on('click', function() { self.saveSettings(); });
        },

        initAccordions: function() {
            // Disable all steps except first
            $('.jase-accordion').each(function(i) {
                if (i > 0) {
                    $(this).addClass('is-disabled');
                }
            });
            // Open first step
            this.openStep(1);
        },

        toggleStep: function(step) {
            var accordion = $('.jase-accordion[data-step="' + step + '"]');
            if (accordion.hasClass('is-active')) {
                accordion.removeClass('is-active');
            } else {
                this.openStep(step);
            }
        },

        openStep: function(step) {
            var accordion = $('.jase-accordion[data-step="' + step + '"]');
            if (accordion.hasClass('is-disabled')) return;

            // Close all
            $('.jase-accordion').removeClass('is-active');
            // Open target
            accordion.addClass('is-active');
            this.currentStep = step;

            // Populate step 6 summary when opened
            if (step === 6) {
                this.populateGenerationSummary();
            }

            // Scroll into view
            setTimeout(function() {
                $('html, body').animate({
                    scrollTop: accordion.offset().top - 50
                }, 300);
            }, 100);
        },

        completeStep: function(step) {
            var accordion = $('.jase-accordion[data-step="' + step + '"]');
            accordion.addClass('is-complete').removeClass('is-active');

            if (this.completedSteps.indexOf(step) === -1) {
                this.completedSteps.push(step);
            }

            // Enable next step
            var next = step + 1;
            $('.jase-accordion[data-step="' + next + '"]').removeClass('is-disabled');
        },

        // ==================== GSC ====================
        connectGSC: function() {
            this.ajaxPost('jase_gsc_get_auth_url', {}, function(data) {
                if (data.url) {
                    window.location.href = data.url;
                }
            });
        },

        loadGSCSites: function() {
            var self = this;
            this.ajaxPost('jase_gsc_get_sites', {}, function(data) {
                var $select = $('#jase-site-select').empty();
                if (data.sites && data.sites.length) {
                    data.sites.forEach(function(site) {
                        $select.append('<option value="' + site.siteUrl + '">' + site.siteUrl + '</option>');
                    });
                    $('#jase-site-selector').show();
                }
            });
        },

        selectGSCSite: function() {
            var siteUrl = $('#jase-site-select').val();
            if (!siteUrl) return;

            var self = this;
            this.ajaxPost('jase_gsc_select_site', { site_url: siteUrl }, function() {
                $('#jase-site-selector').hide();
                $('#jase-site-selected').show().find('#jase-selected-site-url').text(siteUrl);
                $('#jase-query-filters').show();
            });
        },

        fetchQueries: function() {
            var self = this;
            var days = $('#jase-days-filter').val();
            var limit = $('#jase-limit-filter').val();

            $('#jase-queries-result').show();
            $('#jase-queries-loading').show();
            $('#jase-queries-table-wrap').hide();

            this.ajaxPost('jase_gsc_fetch_queries', { days: days, limit: limit }, function(data) {
                $('#jase-queries-loading').hide();
                self.gscQueries = data.queries || [];
                self.selectedGscQueries = [];

                var $tbody = $('#jase-queries-table tbody').empty();
                self.gscQueries.forEach(function(q, i) {
                    $tbody.append(
                        '<tr data-query-index="' + i + '">' +
                        '<td><input type="checkbox" class="jase-query-checkbox" data-index="' + i + '" /></td>' +
                        '<td><strong>' + self.escHtml(q.query) + '</strong></td>' +
                        '<td>' + q.clicks + '</td>' +
                        '<td>' + q.impressions + '</td>' +
                        '<td>' + q.ctr + '%</td>' +
                        '<td>' + q.position + '</td>' +
                        '</tr>'
                    );
                });

                $('#jase-query-select-all').prop('checked', false);
                $('#jase-query-select-count').show();
                self.updateGscSelectCount();
                $('#jase-queries-table-wrap').show();
            }, function() {
                $('#jase-queries-loading').hide();
            });
        },

        toggleGscQuery: function($checkbox) {
            var index = parseInt($checkbox.data('index'));
            var query = this.gscQueries[index];
            var $row = $checkbox.closest('tr');

            if ($checkbox.is(':checked')) {
                if (this.selectedGscQueries.length >= 10) {
                    $checkbox.prop('checked', false);
                    alert('You can select a maximum of 10 queries');
                    return;
                }
                $row.addClass('is-selected');
                this.selectedGscQueries.push(query);
            } else {
                $row.removeClass('is-selected');
                this.selectedGscQueries = this.selectedGscQueries.filter(function(q) {
                    return q.query !== query.query;
                });
            }
            this.updateGscSelectCount();
        },

        updateGscSelectCount: function() {
            $('#jase-gsc-selected-count').text(this.selectedGscQueries.length);
        },

        // ==================== Keywords ====================
        startKeywordResearch: function() {
            if (!this.gscQueries.length) {
                alert('Please fetch GSC queries first');
                return;
            }

            // Use selected queries, or fall back to top 3 if none selected
            var queries = [];
            if (this.selectedGscQueries.length) {
                queries = this.selectedGscQueries.map(function(q) { return q.query; });
            } else {
                queries = this.gscQueries.slice(0, 3).map(function(q) { return q.query; });
            }

            var self = this;
            var location = $('#jase-kw-location').val();

            $('#jase-kw-loading').show();
            $('#jase-kw-results').hide();

            this.ajaxPost('jase_research_keywords_batch', {
                queries: JSON.stringify(queries),
                location: location
            }, function(data) {
                self.allKeywords = data.keywords || [];

                if (!self.allKeywords.length) {
                    $('#jase-kw-loading').hide();
                    alert('No keywords found. Try selecting different queries or changing the country.');
                    return;
                }

                // Now analyze with AI
                self.ajaxPost('jase_analyze_keywords', {
                    keywords: JSON.stringify(self.allKeywords.slice(0, 50))
                }, function(aiData) {
                    $('#jase-kw-loading').hide();
                    self.renderKeywordCards(aiData.analyzed || []);
                    $('#jase-kw-results').show();
                }, function() {
                    $('#jase-kw-loading').hide();
                });
            }, function() {
                $('#jase-kw-loading').hide();
            }, 120000); // 2 min timeout for batch
        },

        renderKeywordCards: function(keywords) {
            var $container = $('#jase-kw-cards').empty();
            this.selectedKeywords = [];

            keywords.forEach(function(kw, i) {
                var scoreClass = kw.score >= 7 ? 'score-high' : (kw.score >= 4 ? 'score-medium' : 'score-low');
                $container.append(
                    '<div class="jase-card" data-type="keyword" data-index="' + i + '">' +
                        '<div class="jase-card-checkbox"></div>' +
                        '<div class="jase-card-header">' +
                            '<span class="jase-card-title">' + JASE.escHtml(kw.text) + '</span>' +
                            '<span class="jase-card-score ' + scoreClass + '">Score: ' + kw.score + '/10</span>' +
                        '</div>' +
                        '<div class="jase-card-meta">' +
                            (kw.volume ? '<span>Volume: ' + kw.volume + '</span>' : '') +
                            (kw.competition_level ? '<span>Competition: ' + kw.competition_level + '</span>' : '') +
                            '<span>Source: Google Suggest</span>' +
                        '</div>' +
                        '<p class="jase-card-reasoning">' + JASE.escHtml(kw.reasoning || '') + '</p>' +
                    '</div>'
                );
            });

            this._kwData = keywords;
        },

        toggleKeywordCard: function($card) {
            var index = parseInt($card.data('index'));
            if ($card.hasClass('is-selected')) {
                $card.removeClass('is-selected');
                this.selectedKeywords = this.selectedKeywords.filter(function(k) { return k._index !== index; });
            } else {
                if (this.selectedKeywords.length >= 5) {
                    alert('You can select a maximum of 5 keywords');
                    return;
                }
                $card.addClass('is-selected');
                var kw = this._kwData[index];
                kw._index = index;
                this.selectedKeywords.push(kw);
            }
            $('#jase-kw-count').text(this.selectedKeywords.length);
        },

        confirmKeywords: function() {
            if (!this.selectedKeywords.length) {
                alert('Please select at least 1 keyword');
                return;
            }

            var self = this;
            this.ajaxPost('jase_select_keywords', {
                selected: JSON.stringify(this.selectedKeywords)
            }, function() {
                $('#jase-kw-results').hide();
                $('#jase-kw-confirmed').show();
            });
        },

        // ==================== Questions ====================
        fetchQuestions: function() {
            var self = this;
            var location = $('#jase-kw-location').val() || 'US';

            $('#jase-q-loading').show();
            $('#jase-q-results').hide();

            this.ajaxPost('jase_fetch_questions', {
                keywords: JSON.stringify(this.selectedKeywords),
                location: location
            }, function(data) {
                $('#jase-q-loading').hide();
                self.renderQuestionCards(data.questions || []);
                $('#jase-q-results').show();
            }, function() {
                $('#jase-q-loading').hide();
            });
        },

        renderQuestionCards: function(questions) {
            var $container = $('#jase-q-cards').empty();
            this.selectedQuestions = [];

            questions.forEach(function(q, i) {
                var scoreClass = q.score >= 7 ? 'score-high' : (q.score >= 4 ? 'score-medium' : 'score-low');
                $container.append(
                    '<div class="jase-card" data-type="question" data-index="' + i + '">' +
                        '<div class="jase-card-checkbox"></div>' +
                        '<div class="jase-card-header">' +
                            '<span class="jase-card-title">' + JASE.escHtml(q.text) + '</span>' +
                            '<span class="jase-card-score ' + scoreClass + '">Score: ' + q.score + '/10</span>' +
                        '</div>' +
                        '<div class="jase-card-meta">' +
                            '<span>Volume: ' + (q.volume || 'N/A') + '</span>' +
                            '<span>Keyword: ' + JASE.escHtml(q.parent_keyword || '') + '</span>' +
                        '</div>' +
                        '<p class="jase-card-reasoning">' + JASE.escHtml(q.reasoning || '') + '</p>' +
                    '</div>'
                );
            });

            this._qData = questions;
        },

        toggleQuestionCard: function($card) {
            var index = parseInt($card.data('index'));
            if ($card.hasClass('is-selected')) {
                $card.removeClass('is-selected');
                this.selectedQuestions = this.selectedQuestions.filter(function(q) { return q._index !== index; });
            } else {
                if (this.selectedQuestions.length >= 5) {
                    alert('You can select a maximum of 5 questions per week');
                    return;
                }
                $card.addClass('is-selected');
                var q = this._qData[index];
                q._index = index;
                this.selectedQuestions.push(q);
            }
            $('#jase-q-count').text(this.selectedQuestions.length);
        },

        confirmQuestions: function() {
            if (!this.selectedQuestions.length) {
                alert('Please select at least 1 question');
                return;
            }

            var self = this;
            this.ajaxPost('jase_select_questions', {
                selected: JSON.stringify(this.selectedQuestions)
            }, function() {
                $('#jase-q-results').hide();
                $('#jase-q-confirmed').show();
            });
        },

        // ==================== Sitemap ====================
        validateSitemap: function() {
            var url = $('#jase-sitemap-url').val().trim();
            if (!url) {
                alert('Please enter a sitemap URL');
                return;
            }

            var self = this;
            $('#jase-sitemap-loading').show();
            $('#jase-sitemap-result').hide();

            this.ajaxPost('jase_validate_sitemap', { sitemap_url: url }, function(data) {
                $('#jase-sitemap-loading').hide();
                $('#jase-sitemap-result').show().html(
                    '<div class="jase-success-badge">' +
                        '<span class="dashicons dashicons-yes-alt"></span> ' +
                        'Sitemap valid - ' + data.url_count + ' URLs found' +
                    '</div>' +
                    '<p style="margin-top:8px;font-size:13px;color:#64748b;">Sample: ' + data.sample.join(', ') + '</p>'
                );
                $('#jase-sitemap-confirmed').show();
            }, function() {
                $('#jase-sitemap-loading').hide();
                $('#jase-sitemap-result').show().html(
                    '<div style="color:#dc2626;font-weight:600;">Failed to validate sitemap. Please check the URL.</div>'
                );
            });
        },

        // ==================== Content Generation ====================
        populateGenerationSummary: function() {
            var $list = $('#jase-gen-questions-list').empty();
            this.selectedQuestions.forEach(function(q) {
                $list.append('<li>' + JASE.escHtml(q.text) + '</li>');
            });
        },

        generateContent: function() {
            if (!this.selectedQuestions.length) {
                alert('No questions selected for content generation');
                return;
            }

            var self = this;
            var imageMode = $('input[name="jase_image_mode"]:checked').val();
            var categoryId = $('#jase-category').val();
            var postStatus = $('#jase-post-status').val();

            $('#jase-generate-content').prop('disabled', true);
            $('#jase-gen-loading').show();
            $('#jase-gen-results').hide();

            this.ajaxPost('jase_generate_content', {
                questions: JSON.stringify(this.selectedQuestions),
                category_id: categoryId,
                post_status: postStatus,
                auto_images: imageMode === 'auto' ? 'true' : 'false',
                image_prompts: '[]'
            }, function(data) {
                $('#jase-gen-loading').hide();
                self.renderGeneratedPosts(data.posts || []);
                $('#jase-gen-results').show();
                self.completeStep(6);
            }, function() {
                $('#jase-gen-loading').hide();
                $('#jase-generate-content').prop('disabled', false);
            }, 300000); // 5 minute timeout for content generation
        },

        renderGeneratedPosts: function(posts) {
            var $container = $('#jase-gen-posts').empty();
            posts.forEach(function(post) {
                if (post.error) {
                    $container.append(
                        '<div class="jase-post-result is-error">' +
                            '<div><span class="jase-post-result-title">' + JASE.escHtml(post.question) + '</span>' +
                            '<br><span class="jase-post-result-status" style="color:#dc2626;">Error: ' + JASE.escHtml(post.error) + '</span></div>' +
                        '</div>'
                    );
                } else {
                    $container.append(
                        '<div class="jase-post-result">' +
                            '<div><span class="jase-post-result-title">' + JASE.escHtml(post.title) + '</span>' +
                            '<br><span class="jase-post-result-status">Status: ' + post.status + '</span></div>' +
                            (post.edit_url ? '<a href="' + post.edit_url + '" class="button jase-post-result-link" target="_blank">Edit Post</a>' : '') +
                        '</div>'
                    );
                }
            });
        },

        // ==================== Settings ====================
        saveSettings: function() {
            var data = {
                rapidapi_key: $('#rapidapi_key').val(),
                openai_api_key: $('#openai_api_key').val(),
                gsc_client_id: $('#gsc_client_id').val(),
                gsc_client_secret: $('#gsc_client_secret').val()
            };

            $('.jase-save-spinner').addClass('is-active');

            this.ajaxPost('jase_save_settings', data, function() {
                $('.jase-save-spinner').removeClass('is-active');
                alert('Settings saved successfully!');
            }, function() {
                $('.jase-save-spinner').removeClass('is-active');
            });
        },

        // ==================== Utilities ====================
        ajaxPost: function(action, data, success, error, timeout) {
            data = data || {};
            data.action = action;
            data.nonce = jaseAdmin.nonce;

            $.ajax({
                url: jaseAdmin.ajaxUrl,
                type: 'POST',
                data: data,
                timeout: timeout || 60000,
                success: function(response) {
                    if (response.success) {
                        if (success) success(response.data);
                    } else {
                        var msg = response.data && response.data.message ? response.data.message : 'Request failed';
                        alert('Error: ' + msg);
                        if (error) error(response);
                    }
                },
                error: function(xhr, status, err) {
                    if (status === 'timeout') {
                        alert('Request timed out. Please try again.');
                    } else {
                        alert('Network error: ' + err);
                    }
                    if (error) error();
                }
            });
        },

        escHtml: function(text) {
            if (!text) return '';
            var div = document.createElement('div');
            div.appendChild(document.createTextNode(text));
            return div.innerHTML;
        }
    };

    $(document).ready(function() {
        JASE.init();
    });

})(jQuery);
