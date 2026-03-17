var console = window.__YUZ_RELEASE_CONSOLE__ || {log:function(){},debug:function(){},info:function(){},warn:function(){},error:function(){}}; var yuz_release_console = console;


/* =======================================================================================
 * YUZ String Translation Editor — ESM safe entry (UMD↔ESM interop)
 * - Zéro CDN
 * - Charge les libs UMD en side-effect puis récupère les globals (window.*)
 * - jQuery: on utilise celui de WordPress (noConflict) si présent
 * ======================================================================================= */
// ——— Side effects: charge les UMD (aucune export n'est attendue)
import '/wp-content/plugins/yuz-tra/assets/vendor/vue/vue.min.js';
import '/wp-content/plugins/yuz-tra/assets/vendor/vue-router/vue-router.min.js';
import '/wp-content/plugins/yuz-tra/assets/vendor/select2/js/select2.full.min.js';
import '/wp-content/plugins/yuz-tra/assets/vendor/he.min.js';
// ——— Service interne du plugin (UN SEUL import !)
import { API_CONFIG, ajaxOptionsFor, nonceFields, translateNow } from './yuz-translation-service.js';
// ——— Bind vers les globals exposés par les UMD
const Vue = window.Vue;
const VueRouter = window.VueRouter;
const $ = window.jQuery || window.$; // WordPress fournit jQuery en noConflict
const heSafe = (typeof window.he !== 'undefined') ? window.he : { decode: (s) => s };
// ——— Diagnostics doux (pas bloquants)
try {
    yuz_release_console.info('[YUZ::diag] UMD globals', 
        { Vue: !!Vue, VueRouter: !!VueRouter, jQuery: !!$, select2: !!($ && $.fn && $.fn.select2), he: !!heSafe }
    );
} catch(_) {}
/** ─────────────────────────────────────────────────────────────────────────────
 * Service côté JS (optionnel)
 * On pioche d’abord dans window.YUZ_TranslationService si présent (UMD),
 * sinon on fournit des fallbacks légers pour éviter de crasher.
 * (Si tu as un vrai module ESM local, importe-le AVEC EXTENSION: "./yuz-translation-service.js")
 * ──────────────────────────────────────────────────────────────────────────── */
const SVC = window.YUZ_TranslationService || {};
// Les exports sont déjà définis via l'import en haut, pas besoin de redéclarer
// export const API_CONFIG = SVC.API_CONFIG || { ... }; // SUPPRIMÉ : doublon
export { ajaxOptionsFor, nonceFields, translateNow }; // Réexportation des autres fonctions
// ————————————————————————————————————————————————————————————————————————————————
// Sécurité & diagnostics
// ————————————————————————————————————————————————————————————————————————————————
// Gestion des promesses non catchées (diag UI, pas bloquant)
window.addEventListener('unhandledrejection', (event) => {
    try {
        // YUZ_Assets est supposé dispo globalement côté admin
        window.YUZ_Assets?.log_colored?.('critical', 'Unhandled Promise Rejection', {
            reason: event.reason?.message || event.reason,
            stack: event.reason?.stack || 'No stack trace available'
        });
    } catch (_) {}
    event.preventDefault();
});
// ————————————————————————————————————————————————————————————————————————————————
// Utilitaires locaux
// ————————————————————————————————————————————————————————————————————————————————
// heSafe → utilise directement le package 'he' (plus de garde global)
//const heSafe = he; // Déjà défini plus haut
let requestCount = 0;
let lastReset = Date.now();
const cache = {
    get(key) {
        try {
            const cached = localStorage.getItem(key);
            if (cached) {
                const { value, timestamp } = JSON.parse(cached);
                if (Date.now() - timestamp < API_CONFIG.CACHE_TTL) return value;
                localStorage.removeItem(key);
            }
        } catch (_) {}
        return null;
    },
    set(key, value) {
        try {
            localStorage.setItem(key, JSON.stringify({ value, timestamp: Date.now() }));
        } catch (_) {}
    }
};
async function checkRateLimit() {
    const now = Date.now();
    if (now - lastReset >= 60000) {
        requestCount = 0;
        lastReset = now;
    }
    if (requestCount >= API_CONFIG.RATE_LIMIT) {
        const waitTime = 60000 - (now - lastReset);
        window.YUZ_Assets?.log_colored?.('warning', `Rate limit reached, waiting ${waitTime}ms`);
        await new Promise((resolve) => setTimeout(resolve, waitTime));
    }
}

// ————————————————————————————————————————————————————————————————————————————————
// Composant principal: StringTranslation (identique fonctionnellement, nettoyé)
// ————————————————————————————————————————————————————————————————————————————————

const StringTranslation = {
  template: `
<div class="wrap wp-admin wp-core-ui" id="yuz-string-translation">
  <div class="yuz-title-rescan">
    <div class="wp-heading-inline">
      <span class="yuz-translation-tab-title-name">{{ (parentTab !== false) ? parentTab.name : currentTab.name }}</span>
      <button v-if="currentTab.scan_gettext" class="page-title-action-rescan" :class="{ 'yuz-button-busy-animation': scanningInProgress }" :disabled="scanningInProgress" id="yuz-rescan-gettext" @click="startGettextScan">{{ rescanButtonText }}</button>
    </div>
  </div>

  <div v-if="showFiltersAndTable">
    <a v-if="currentTab.add_new" class="page-title-action" href="#">Add New</a>
    <hr class="wp-header-end">

    <ul v-if="parentTab !== false" class="subsubsub">
      <li v-for="(category, categoryPath, index) in parentTab.categories">
        <span v-if="category.name === 'Other Slugs'" class="yuz-tooltip-other-slugs" :title="stEditorStrings.other_slugs_tooltip"></span>
        <router-link :class="{ 'nav-tab-active': categoryPath === translationType }" :to="'/' + parentTranslationType + '/' + categoryPath + '/'"><!-- -->{{ category.name }}</router-link>
        <span v-if="index !== Object.keys(parentTab.categories).length - 1">|</span>
      </li>
    </ul>

    <div class="tablenav top yuz-filters-container">
      <div class="yuz-translation-status-container yuz-filters-container-item">
        <div class="yuz-filter" id="yuz-filter-translation-status">
          <label v-for="(status, status_key) in translationStatusFilters.translation_status" class="yuz-translation-status-checkbox" :for="'yuz-filter-translation-status-' + status_key">
            <input type="checkbox" :id="'yuz-filter-translation-status-' + status_key" v-model="filterValues[status_key]" @change="filter">
            {{ status }}
          </label>
        </div>

        <div class="yuz-filter-dropdowns">
          <span class="yuz-filter">
            <span class="yuz-tooltip-toggle yuz-tooltip-toggle-language" style="visibility: hidden" :data-tooltip="stEditorStrings.filter_by_language_tooltip" tabindex="0">
              <select class="yuz-filter-select" name="yuz-language" id="yuz-filter-language" v-model="filterValues.language" @change="filter">
                <option :value="yuzTraSettings.default_language">{{ stEditorStrings.filter_by_language }}</option>
                <option v-for="language in settings['translation-languages']" v-show="currentTab.show_original_language || language !== yuzTraSettings.default_language" :value="language">{{ languageNames[language] }}</option>
              </select>
            </span>
          </span>

          <span v-for="(filter, filter_key) in currentTab.filters" class="yuz-filter">
            <span class="yuz-tooltip-toggle yuz-tooltip-toggle-filter-by-post" style="visibility: hidden" :data-tooltip="filter[yuzTraSettings.default_language] || filter.default" tabindex="0">
              <select class="yuz-filter-select" :name="filter_key" :id="'yuz-filter-' + filter_key" v-model="filterValues[filter_key]" @change="filter">
                <option v-for="(option, option_key) in filter" :value="option_key" :selected="option_key === yuzTraSettings.default_language">{{ option }}</option>
              </select>
            </span>
          </span>

          <span class="yuz-tooltip-toggle yuz-tooltip-toggle-filter-button" style="visibility: hidden" :data-tooltip="stEditorStrings.filter_tooltip" tabindex="0">
            <input class="button" type="submit" :value="stEditorStrings.filter" @click="filter">
          </span>

          <span class="yuz-tooltip-toggle yuz-tooltip-toggle-clear-filters" style="visibility: hidden" :data-tooltip="stEditorStrings.clear_filter_tooltip" tabindex="0">
            <a :class="{ 'yuz-clear-filter-disabled': clearFilterDisabled }" id="yuz-clear-filter-button" @click="clear_filter">
              <span id="yuz-clear-filter-x"></span><!-- -->{{ stEditorStrings.clear_filter }}
            </a>
          </span>
        </div>
      </div>

      <div class="yuz-search-box yuz-filters-container-item">
        <label class="screen-reader-text" for="yuz-string-search-input">{{ currentTab.search_name }}</label>
        <input type="search" id="yuz-string-search-input" :placeholder="stEditorStrings.search_placeholder" name="s" v-model="filterValues.s" @keyup.enter="filter">
        <span class="yuz-tooltip-toggle yuz-tooltip-toggle-search-submit" style="visibility: hidden" :data-tooltip="stEditorStrings.search_tooltip" tabindex="0">
          <input class="button" type="submit" id="yuz-search-submit" :value="currentTab.search_name" @click="filter">
        </span>
      </div>
    </div>

    <div class="tablenav top yuz-table-actions">
      <bulk-actions :stEditorStrings="stEditorStrings" :defaultActions="defaultActions" :tableControls="tableControls" :currentTab="currentTab" :ajaxUrl="ajaxUrl" :listenForEvents="true"></bulk-actions>
      <pagination v-model.lazy.number="currentPage" :stEditorStrings="stEditorStrings" :totalItems="totalItems" :totalNumberOfPages="totalNumberOfPages" :wrongPageValue="wrongPageValue"></pagination>
      <br class="clear">
    </div>

    <strings-table
      v-model="tableControls"
      :dictionary="dictionary"
      :currentTab="currentTab"
      :settings="settings"
      :languageNames="languageNames"
      :translationStatusFilters="translationStatusFilters"
      :defaultActions="defaultActions"
      :flagsPath="flagsPath"
      :stEditorStrings="stEditorStrings"
      :currentLanguage="currentLanguage"
      :config="config"></strings-table>

    <div class="tablenav top yuz-table-actions">
      <bulk-actions :stEditorStrings="stEditorStrings" :defaultActions="defaultActions" :tableControls="tableControls" :currentTab="currentTab" :ajaxUrl="ajaxUrl" :listenForEvents="false"></bulk-actions>
      <pagination v-model.lazy.number="currentPage" :stEditorStrings="stEditorStrings" :totalItems="totalItems" :totalNumberOfPages="totalNumberOfPages" :wrongPageValue="wrongPageValue"></pagination>
    </div>

    <div class="yuz-string-translation-end"></div>
  </div>

  <div v-if="!showFiltersAndTable" v-html="extraText"></div>
</div>
`,
  components: {
    BulkActions: {
      template: `
<div v-if="currentTab.add_new" class="yuz-bulk-actions alignleft actions bulkactions">
  <select v-model="actionToApply" name="action" id="yuz-bulk-action-selector-top">
    <option v-for="(action, action_key) in defaultActions.bulk_actions" :value="action_key">{{ action.name }}</option>
  </select>
  <input class="button" type="submit" :value="stEditorStrings.apply" @click="applyAction(actionToApply, tableControls.checkedStrings)">
</div>
`,
      props: ['stEditorStrings', 'defaultActions', 'tableControls', 'currentTab', 'ajaxUrl', 'listenForEvents'],
      data() { return { actionToApply: yuzTraSettings.default_language }; },
      created() { if (this.listenForEvents) document.addEventListener('yuz_trigger_perform_action_event', this.applyIndividualAction); },
      methods: {
        applyIndividualAction(e) { this.applyAction(e.detail.action, [e.detail.stringIndex]); },
        applyAction(action, checkedStrings) {
          if (this.defaultActions.bulk_actions[action] && action !== yuzTraSettings.default_language && checkedStrings.length >= 1) {
            const promptText = `${this.stEditorStrings[action + '_warning']}\n\n${this.tableControls.selectAllOrVisible ? this.stEditorStrings[this.tableControls.selectAllOrVisible + '_warning'] + '\n\n' : ''}${this.stEditorStrings.type_a_word_for_security} ${action}`;
            if (prompt(promptText, '') === action) {
              window.YUZ_Assets?.log_colored?.('info', `Initiating bulk action: ${action}`, { checkedStrings });

              const _action = `yuz_string_translation_bulk_action_${action}`;
              const _opts   = ajaxOptionsFor(_action);

              $.ajax({
                url: yuzTraSettings.ajax_url,
                type: 'POST',
                dataType: 'json',
                timeout: _opts.timeout,
                data: {
                  action: _action,
                  ...nonceFields(_action),
                  checked_strings: JSON.stringify(checkedStrings),
                  select_all_or_visible: this.tableControls.selectAllOrVisible,
                  query: JSON.stringify(this.$route.query)
                },
                success: (response) => {
                  if (response.success) {
                    if (response.data.dictionary) this.$emit('update:dictionary', response.data.dictionary);
                    if (response.data.totalItems) this.$emit('update:totalItems', response.data.totalItems);
                    window.YUZ_Assets?.log_colored?.('success', `Bulk action ${action} applied`, { checkedStrings });
                  } else {
                    window.YUZ_Assets?.log_colored?.('critical', `Error applying bulk action ${action}`, { error: response.data?.message });
                  }
                },
                error: (xhr) => {
                  window.YUZ_Assets?.log_colored?.('critical', `AJAX error applying bulk action ${action}`, { error: xhr.responseText });
                },
                complete: (_xhr, status) => {
                  if (status === 'timeout') {
                    window.YUZ_Assets?.log_colored?.('critical', `AJAX timeout for bulk action ${action}`, { timeout: _opts.timeout });
                  }
                }
              });
            } else {
              alert(this.stEditorStrings.incorrect_word_typed);
              window.YUZ_Assets?.log_colored?.('warning', 'Bulk action aborted: incorrect security word');
            }
          }
        }
      }
    },

    Pagination: {
      template: `
<div class="tablenav-pages">
  <span class="displaying-num">{{ totalItems === null ? 0 : totalItems }} {{ stEditorStrings.items }}</span>
  <span class="pagination-links">
    <span class="yuz-tooltip-toggle yuz-tooltip-toggle-previous-navigation" style="visibility: hidden" :data-tooltip="stEditorStrings.previous_page" tabindex="0">
      <span class="tablenav-pages-navspan button" :class="{ disabled: value <= 1 }" @click="$emit('input', value <= 1 ? value : value - 1)">
        <span>
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="30" height="30" aria-hidden="true" focusable="false">
            <path d="M18.3 11.7c-.6-.6-1.4-.9-2.3-.9H6.7l2.9-3.3-1.1-1-4.5 5L8.5 16l1-1-2.7-2.7H16c.5 0 .9.2 1.3.5 1 1 1 3.4 1 4.5v.3h1.5v-.2c0-1.5 0-4.3-1.5-5.7z"/>
          </svg>
        </span>
      </span>
    </span>

    <span class="yuz-tooltip-toggle yuz-tooltip-toggle-pagination" style="visibility: hidden" :data-tooltip="wrongPageValue ? stEditorStrings.wrong_page : stEditorStrings.navigate_to_page" tabindex="0">
      <span class="paging-input">
        <input class="current-page" :class="{ 'wrong-value': wrongPageValue }" type="text" name="paged" size="1" aria-describedby="table-paging" :value="value" @change="$emit('input', $event.target.value)">
      </span>
      <span class="tablenav-paging-text">
        {{ stEditorStrings.of }}
        <span class="total-pages">{{ totalNumberOfPages }}</span>
      </span>
    </span>

    <span class="yuz-tooltip-toggle yuz-tooltip-toggle-next-navigation" style="visibility: hidden" :data-tooltip="stEditorStrings.next_page" tabindex="0">
      <span class="tablenav-pages-navspan button" :class="{ disabled: value >= totalNumberOfPages }" @click="$emit('input', value >= totalNumberOfPages ? value : value + 1)">
        <span>
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="30" height="30" aria-hidden="true" focusable="false">
            <path d="M15.6 6.5l-1.1 1 2.9 3.3H8c-.9 0-1.7.3-2.3.9-1.4 1.5-1.4 4.2-1.4 5.6v.2h1.5v-.2c0-1.1 0-3.5 1-4.5.3-.3.7-.5 1.3-.5h9.2L14.5 15l1.1 1.1 4.6-4.6-4.6-5z"/>
          </svg>
        </span>
      </span>
    </span>
  </span>
</div>
`,
      props: ['value', 'totalNumberOfPages', 'stEditorStrings', 'totalItems', 'wrongPageValue'],
      data() { return { page: this.value }; },
      watch: { value(newVal) { this.page = newVal; } }
    },

    StringsTable: {
      template: `
<div id="yuz-string-tables-root">
  <div id="yuz-string-tables-container">
    <table class="wp-list-table widefat fixed striped yuz-strings-table">
      <thead>
        <table-head :stEditorStrings="stEditorStrings" :currentLanguage="currentLanguage" :languageNames="languageNames" :currentTab="currentTab" v-model="tableHeadControls"></table-head>
      </thead>
      <tbody>
        <tr v-show="showLoadingScreen">
          <td :colspan="numberOfColumns">
            <div class="yuz-loading-screen" id="yuz-table-loader">
              <svg class="yuz-loader" width="65px" height="65px" viewBox="0 0 66 66" xmlns="http://www.w3.org/2000/svg">
                <circle class="yuz-circle" fill="none" stroke-width="6" stroke-linecap="round" cx="33" cy="33" r="30"/>
              </svg>
            </div>
          </td>
        </tr>

        <tr v-show="Object.entries(dictionary).length === 0 && !showLoadingScreen">
          <td :colspan="numberOfColumns">
            {{ stEditorStrings.no_strings_match_query }} {{ currentTab.scan_gettext ? stEditorStrings.no_strings_match_rescan : '' }}
          </td>
        </tr>

        <tr v-for="(string, index) in dictionary" v-show="!showLoadingScreen && !string.hasOwnProperty('unsavedChanges')" class="yuz-table-row yuz-string-table-row" :id="'yuz-string-table-row-' + index">
          <td style="width: 0px"></td>

          <td v-for="(value, column) in currentTab.table_columns" :class="'yuz-table-data-' + column" v-if="column !== 'translated' && column !== 'id' || currentLanguage !== yuzTraSettings.default_language">
            <div v-if="column === 'original'">
              <strong>
                <a class="row-title yuz-anchor-action">
                  <seemore-string :string="string[column]" :stEditorStrings="stEditorStrings" :config="config" @click="performAction('edit', index)"></seemore-string>
                </a>
              </strong>
              <div class="row-actions">
                <span v-for="(action, action_key, idx) in defaultActions.actions" :class="action_key">
                  <a class="yuz-anchor-action" @click="performAction(action_key, index)">{{ action }}</a>
                </span>
              </div>
            </div>

            <div v-else-if="column === 'translated'">
              <seemore-string :string="string.translationsArray[currentLanguage] ? maybeDecode(string.translationsArray[currentLanguage][column]) : ''" :stEditorStrings="stEditorStrings" :config="config" @click="performAction('edit', index)"></seemore-string>
            </div>

            <div v-else-if="column === 'id'">
              <seemore-string :string="string.translationsArray[currentLanguage] ? maybeDecode(string.post_id) : ''" :stEditorStrings="stEditorStrings" :config="config" @click="performAction('edit', index)"></seemore-string>
            </div>

            <div v-else>
              {{ string[column] }}
            </div>
          </td>

          <td v-if="currentLanguage !== yuzTraSettings.default_language" class="yuz-translation-status-entry-wrapper">
            <div class="yuz-translation-status-entry">
              <span v-for="lang in translationLanguages" class="yuz-language-translation-status" v-show="currentTab.show_original_language || lang !== yuzTraSettings.default_language">
                <span class="yuz-language-translation-status-item" :title="translationStatusFilters.translation_status[statusName[string.translationsArray[lang].status]] + ' ' + stEditorStrings.in + ' ' + languageNames[lang]">
                  <span :class="{
        'yuz-human-reviewed-green': string.translationsArray[lang] && parseInt(string.translationsArray[lang].status, 10) >= 4,
        'yuz-automatic-translated-blue': string.translationsArray[lang] && parseInt(string.translationsArray[lang].status, 10) > 0 && parseInt(string.translationsArray[lang].status, 10) < 4,
        'yuz-untranslated-red': !string.translationsArray[lang] || parseInt(string.translationsArray[lang].status, 10) === 0
      }">
                    {{ translationStatusFilters.translation_status[statusName[string.translationsArray[lang].status]] }}
                  </span>
                </span>
              </span>
            </div>
          </td>
        </tr>
      </tbody>

      <tfoot>
        <table-head :stEditorStrings="stEditorStrings" :currentLanguage="currentLanguage" :languageNames="languageNames" :currentTab="currentTab" v-model="tableHeadControls"></table-head>
      </tfoot>
    </table>
  </div>
</div>
`,
      components: {
        TableHead: {
          template: `
<tr>
  <th class="manage-column column-cb check-column yuz-check-column" style="width: 0px" id="cb"></th>
  <th
    v-for="(column, column_key) in currentTab.table_columns"
    class="manage-column column-primary yuz-fixed-columns"
    :class="{ sorted: orderBy === column_key, sortable: orderBy !== column_key, asc: orderBy === column_key && order === 'asc', desc: orderBy === column_key && order === 'desc' || orderBy !== column_key }"
    scope="col"
    :id="'yuz-column-' + column_key"
    @click="sortByColumn(column_key)"
    v-if="column_key !== 'translated' && column_key !== 'id' || currentLanguage !== yuzTraSettings.default_language">
    <span class="yuz-tooltip-toggle yuz-tooltip-toggle-table-head" style="visibility: hidden" :data-tooltip="column_key === 'original' ? stEditorStrings.sort_by_column : ''" tabindex="0">
      <a v-if="column_key === 'original'" class="yuz-anchor-action">
        <span>{{ column }}</span>
        <span class="sorting-indicator"></span>
      </a>
      <span v-else>{{ column }}</span>
    </span>
  </th>
  <th v-if="currentLanguage !== yuzTraSettings.default_language" class="manage-column yuz-translation-status-column" scope="col">
    {{ languageNames[currentLanguage] }} {{ stEditorStrings.translation_status }}
  </th>
</tr>
`,
          props: ['value', 'stEditorStrings', 'currentLanguage', 'languageNames', 'currentTab'],
          data() { return { order: '', orderBy: '' }; },
          created() { this.setOrderValues(); },
          watch: {
            '$route'(to, from) {
              this.setOrderValues();
              window.YUZ_Assets?.log_colored?.('info', 'Route changed', { to: to.path, from: from.path });
            }
          },
          methods: {
            setOrderValues() {
              if (this.$route.query.order && ['asc', 'desc'].includes(this.$route.query.order)) this.order = this.$route.query.order;
              if (this.$route.query.orderby && this.currentTab.table_columns[this.$route.query.orderby]) this.orderBy = this.$route.query.orderby;
              window.YUZ_Assets?.log_colored?.('info', 'Set order values', { order: this.order, orderBy: this.orderBy });
            },
            sortByColumn(column) {
              if (column === 'original') {
                const order = this.order === 'asc' ? 'desc' : 'asc';
                this.order = order;
                this.orderBy = column;
                if (this.$route.query.order !== order) {
                  const query = { ...this.$route.query, order, orderby: column, page: '1' };
                  this.$router.push({ path: this.$route.path, query }).catch(err => {
                    window.YUZ_Assets?.log_colored?.('error', 'Router push failed', { error: err.message });
                  });
                }
                window.YUZ_Assets?.log_colored?.('info', 'Sorted by column', { column, order });
              }
            }
          }
        },

        SeemoreString: {
          template: `
<span class="yuz-view-more-string">
  <span @click="$emit('click')">{{ seeMore ? string : shortString }}</span>
  <span v-if="isLongString" class="yuz-see-more" @click="seeMore = !seeMore">{{ seeMore ? stEditorStrings.see_less : stEditorStrings.see_more }}</span>
</span>
`,
          props: ['string', 'stEditorStrings', 'config'],
          data() { return { seeMore: false, maxLength: this.config.see_more_max_length }; },
          computed: {
            shortString() { return this.isLongString ? (this.string || '').substr(0, this.maxLength) + '...' : (this.string || ''); },
            isLongString() { return (this.string || '').length > this.maxLength; }
          }
        }
      },

      props: ['dictionary', 'totalItems', 'translationType', 'parentTranslationType', 'currentTab', 'parentTab'],
      data() {
        if (!window.yuzTraSettings || !window.yuzTraSettings.yuz_settings || !window.yuzTraSettings.language_names || !window.yuzTraSettings.editor_nonces || !window.yuzTraSettings.st_editor_strings) {
          window.YUZ_Assets?.log_colored?.('critical', 'Required yuzTraSettings are missing', {
            yuz_settings: !!window.yuzTraSettings?.yuz_settings,
            language_names: !!window.yuzTraSettings?.language_names,
            editor_nonces: !!window.yuzTraSettings?.editor_nonces,
            st_editor_strings: !!window.yuzTraSettings?.st_editor_strings
          });
          throw new Error('Required yuzTraSettings are missing');
        }
        return {
          stEditorStrings: window.yuzTraSettings.st_editor_strings,
          defaultActions: window.yuzTraSettings.default_actions,
          translationStatusFilters: window.yuzTraSettings.translation_status_filters,
          config: window.yuzTraSettings.config,
          settings: window.yuzTraSettings.yuz_settings,
          languageNames: window.yuzTraSettings.language_names,
          ajaxUrl: window.yuzTraSettings.ajax_url,
          flagsPath: window.yuzTraSettings.flags_path,
          nonces: window.yuzTraSettings.editor_nonces,
          currentQuery: this.$route ? this.$route.query : {},
          presentationData: [],
          filterValues: {},
          currentPage: 1,
          wrongPageValue: false,
          currentLanguage: yuzTraSettings.default_language,
          tableControls: { checkedStrings: [], selectAllOrVisible: '' },
          rescanButtonText: window.yuzTraSettings.st_editor_strings.rescan_gettext,
          scanningInProgress: false,
          upgradedGettext: window.yuzTraSettings.upgraded_gettext,
          noticeUpgradeGettext: window.yuzTraSettings.notice_upgrade_gettext,
          noticeUpgradeSlugs: window.yuzTraSettings.notice_upgrade_slugs,
          upsaleSlugs: window.yuzTraSettings.upsale_slugs,
          upsaleSlugsText: window.yuzTraSettings.upsale_slugs_text,
          showFiltersAndTable: false,
          extraText: '',
          clearFilterDisabled: true,
          translationLanguages: window.yuzTraSettings.yuz_settings['translation-languages'],
          statusName: { 5: 'archived', 4: 'published', 3: 'queued', 2: 'pending_review', 1: 'machine_translated', 0: 'not_translated' },
          state: { loading: false, error: null },
          showLoadingScreen: false
        };
      },
      watch: {
        dictionary() { window.YUZ_Assets?.log_colored?.('info', 'Dictionary updated', { dictionaryLength: this.dictionary.length }); },
        currentPage(newPage, oldPage) {
          if (newPage !== oldPage) {
            const page = this.validatePage(newPage);
            if (page === null) {
              this.wrongPageValue = true;
              window.YUZ_Assets?.log_colored?.('warning', 'Invalid page number', { page: newPage });
            } else {
              this.wrongPageValue = false;
              if (this.$route.query.page != page) {
                const query = { ...this.$route.query, page };
                this.$router.push({ path: this.$route.path, query }).catch(err => {
                  window.YUZ_Assets?.log_colored?.('error', 'Router push failed', { error: err.message });
                  this.state.error = err.message;
                });
              }
              window.YUZ_Assets?.log_colored?.('info', 'Page changed', { newPage, oldPage });
            }
          }
        },
        '$route'(to, from) {
          this.setFilterValues(); this.setExtraText(); this.changeTopofSlugs();
          window.YUZ_Assets?.log_colored?.('info', 'Route changed', { to: to.path, from: from.path });
        },
        currentLanguage() {
          this.updateColumns();
          window.YUZ_Assets?.log_colored?.('info', 'Current language changed', { language: this.currentLanguage });
        },
        'tableControls.selectAllOrVisible'() {
          this.checkedStrings = [];
          this.dictionary.forEach((_, index) => this.checkedStrings.push(index));
          window.YUZ_Assets?.log_colored?.('info', 'Select all or visible changed', { selectAllOrVisible: this.tableControls.selectAllOrVisible });
        },
        checkedStrings() {
          this.$emit('input', { checkedStrings: this.checkedStrings, selectAllOrVisible: this.tableControls.selectAllOrVisible });
          window.YUZ_Assets?.log_colored?.('info', 'Checked strings updated', { checkedStrings: this.checkedStrings });
        }
      },
      computed: {
        totalNumberOfPages() {
          const pages = this.totalItems === null ? 0 : Math.ceil(this.totalItems / this.config.items_per_page);
          window.YUZ_Assets?.log_colored?.('info', 'Computed total number of pages', { pages });
          return pages;
        },
        numberOfColumns() {
          let count = 1;
          for (const column in this.currentTab.table_columns) {
            if (Object.prototype.hasOwnProperty.call(this.currentTab.table_columns, column) && (this.currentLanguage !== yuzTraSettings.default_language || (this.currentLanguage === yuzTraSettings.default_language && column !== 'translated' && column !== 'id'))) count++;
          }
          if (this.currentLanguage !== yuzTraSettings.default_language) count++;
          window.YUZ_Assets?.log_colored?.('info', 'Computed number of columns', { count });
          return count;
        }
      },
      created() {
        this.setFilterValues();
        this.currentLanguage = this.filterValues.language || yuzTraSettings.default_language;
        this.setExtraText();
        this.changeTopofSlugs();
        window.YUZ_Assets?.log_colored?.('success', 'StringTranslation component created', { settings: this.settings });
      },
      mounted() {
        if (!document.getElementById('yuz-editor-container') || !window.location.href.includes('yuz-string-translation-editor')) {
          window.YUZ_Assets?.log_colored?.('warning', 'Editor not open, skipping mount');
          return;
        }
        window.YUZ_Assets?.log_colored?.('info', 'StringTranslation mounted start');
        if (!window.yuzTraSettings || !window.yuzTraSettings.yuz_settings || !window.yuzTraSettings.language_names || !window.yuzTraSettings.editor_nonces) {
          window.YUZ_Assets?.log_colored?.('critical', 'Required yuzTraSettings are missing', {
            yuz_settings: !!window.yuzTraSettings?.yuz_settings,
            language_names: !!window.yuzTraSettings?.language_names,
            editor_nonces: !!window.yuzTraSettings?.editor_nonces
          });
          alert('String translation editor cannot initialize due to missing configuration. Please check plugin settings.');
          return;
        }
        if (performance.getEntriesByType('navigation')[0]) this.changeTopofSlugs();

        window.addEventListener('yuz_trigger_show_loading_table_event', this.setLoadingScreen);
        window.addEventListener('yuz_trigger_hide_loading_table_event', this.hideLoadingScreen);

        // select2 init (utilise l'import jQuery + side-effect select2)
        $('#yuz-filter-language').select2({
          width: '100%',
          templateResult: (data) => {
            if (!data.element) return data.text;
            const code = data.element.value;
            if (this.settings.floating_format.includes('flags') && window.yuzTraSettings.flags_file_name[code]) {
              return $(`<span><img src="${this.flagsPath}${window.yuzTraSettings.flags_file_name[code]}" style="width: 16px; margin-right: 5px;">${data.text}</span>`);
            }
            return data.text;
          }
        });

        window.YUZ_Assets?.log_colored?.('success', 'String translation editor initialized successfully', { settings: this.settings });
      },
      methods: {
        updateColumns() {
          this.translationLanguages = this.currentLanguage === yuzTraSettings.default_language ? this.settings['translation-languages'] : [this.currentLanguage];
          window.YUZ_Assets?.log_colored?.('info', 'Updated translation languages', { translationLanguages: this.translationLanguages });
        },
        setLoadingScreen() { this.showLoadingScreen = true;  window.YUZ_Assets?.log_colored?.('info', 'Loading screen set'); },
        hideLoadingScreen() { this.showLoadingScreen = false; window.YUZ_Assets?.log_colored?.('info', 'Loading screen hidden'); },
        maybeDecode(str) {
          try { return heSafe.decode(str); }
          catch (e) { window.YUZ_Assets?.log_colored?.('warning', 'Failed to decode string', { error: e.message }); return str; }
        },
        filter() {
          const query = this.buildQuery(this.filterValues);
          this.clearFilterDisabled = Object.keys(query).length === 0;
          this.$router.push({ path: this.$route.path, query }).catch(err => {
            window.YUZ_Assets?.log_colored?.('error', 'Router push failed', { error: err.message });
          });
          this.currentLanguage = this.filterValues.language || yuzTraSettings.default_language;
          this.currentPage = 1;
          window.YUZ_Assets?.log_colored?.('info', 'Applied filter', { query });
        },
        clear_filter() {
          if (!this.clearFilterDisabled) {
            this.clearFilterDisabled = true;
            this.$router.push({ path: this.$route.path, query: {} }).catch(err => {
              window.YUZ_Assets?.log_colored?.('error', 'Router push failed', { error: err.message });
            });
            this.currentLanguage = yuzTraSettings.default_language;
            this.currentPage = 1;
            window.YUZ_Assets?.log_colored?.('info', 'Cleared filters');
          }
        },
        buildQuery(filterValues) {
          let query = {};
          let statusValue = null;
          let boolAddStatusToQuery = false;
          for (const status_key in this.translationStatusFilters.translation_status) {
            if (Object.prototype.hasOwnProperty.call(this.translationStatusFilters.translation_status, status_key)) {
              if (statusValue === null) statusValue = filterValues[status_key];
              if (statusValue !== filterValues[status_key]) boolAddStatusToQuery = true;
            }
          }
          if (boolAddStatusToQuery) query = Object.assign(query, this.buildQueryForFilter(this.translationStatusFilters.translation_status, filterValues));
          query = Object.assign(query, this.buildQueryForFilter(this.currentTab.filters, filterValues));
          if (filterValues.language !== yuzTraSettings.default_language) query.language = filterValues.language;
          if (filterValues.s !== '') query.s = filterValues.s;
          if (this.$route.query.order && ['asc', 'desc'].includes(this.$route.query.order)) query.order = this.$route.query.order;
          if (this.$route.query.orderby && this.currentTab.table_columns[this.$route.query.orderby]) query.orderby = this.$route.query.orderby;
          window.YUZ_Assets?.log_colored?.('info', 'Built query for filter', { query });
          return query;
        },
        buildQueryForFilter(filter, filterValues) {
          const returnQuery = {};
          for (const option_key in filter) {
            if (Object.prototype.hasOwnProperty.call(filter, option_key) && filterValues[option_key] !== yuzTraSettings.default_language) returnQuery[option_key] = filterValues[option_key];
          }
          return returnQuery;
        },
        setFilterValues() {
          this.filterValues.translation_status = {};
          for (const status_key in this.translationStatusFilters.translation_status) {
            if (Object.prototype.hasOwnProperty.call(this.translationStatusFilters.translation_status, status_key)) {
              this.filterValues[status_key] = this.$route.query[status_key] !== undefined ? !(this.$route.query[status_key] === 'false' || this.$route.query[status_key] === false) : true;
            }
          }
          this.filterValues.language = this.$route.query.language && this.settings['translation-languages'].includes(this.$route.query.language) ? this.$route.query.language : yuzTraSettings.default_language;
          for (const filter_key in this.currentTab.filters) {
            if (Object.prototype.hasOwnProperty.call(this.currentTab.filters, filter_key)) {
              this.filterValues[filter_key] = this.$route.query[filter_key] && this.currentTab.filters[filter_key][this.$route.query[filter_key]] ? this.$route.query[filter_key] : (this.currentTab.filters[filter_key][yuzTraSettings.default_language] ? yuzTraSettings.default_language : Object.keys(this.currentTab.filters[filter_key])[0]);
            }
          }
          this.filterValues.s = this.$route.query.s && this.$route.query.s !== '' ? this.$route.query.s : '';
          this.currentPage = this.$route.query.page && this.validatePage(this.$route.query.page) !== null ? this.validatePage(this.$route.query.page) : 1;
          window.YUZ_Assets?.log_colored?.('info', 'Set filter values', { filterValues: this.filterValues });
        },
        validatePage(pageNumber) {
          const parsedPageNumber = parseInt(pageNumber, 10);
          const valid = (1 <= parsedPageNumber && (this.totalItems === null || parsedPageNumber <= this.totalNumberOfPages)) ? parsedPageNumber : null;
          window.YUZ_Assets?.log_colored?.('info', 'Validated page number', { pageNumber, valid });
          return valid;
        },
        startGettextScan() {
          this.scanningInProgress = true;
          this.rescanButtonText = this.stEditorStrings.scanning_gettext;
          this.sendAjaxToScanGettext();
          window.YUZ_Assets?.log_colored?.('info', 'Started gettext scan');
        },
        sendAjaxToScanGettext() {
          window.YUZ_Assets?.log_colored?.('info', 'Initiating gettext scan');
          const opts = ajaxOptionsFor('yuz_scan_gettext');
          $.ajax({
            url: yuzTraSettings.ajax_url,
            type: 'POST',
            dataType: 'json',
            timeout: opts.timeout,
            data: { action: 'yuz_scan_gettext', ...nonceFields('yuz_scan_gettext') },
            success: (response) => {
              if (response.success && response.data.progress_message) {
                if (response.data.completed === true) {
                  this.rescanButtonText = this.stEditorStrings.gettext_scan_completed;
                  this.scanningInProgress = false;
                  window.YUZ_Assets?.log_colored?.('success', 'Gettext scan completed', { response: response.data });
                } else {
                  this.rescanButtonText = response.data.progress_message;
                  this.sendAjaxToScanGettext();
                  window.YUZ_Assets?.log_colored?.('info', 'Gettext scan in progress', { progress_message: response.data.progress_message });
                }
              } else {
                this.rescanButtonText = this.stEditorStrings.gettext_scan_error;
                this.scanningInProgress = false;
                window.YUZ_Assets?.log_colored?.('critical', 'Gettext scan failed', { response: response.data });
              }
            },
            error: (xhr) => {
              this.rescanButtonText = this.stEditorStrings.gettext_scan_error;
              this.scanningInProgress = false;
              this.state.error = xhr.responseText;
              window.YUZ_Assets?.log_colored?.('critical', 'Error during gettext scan', { error: xhr.responseText });
            },
            complete: (_xhr, status) => {
              if (status === 'timeout') {
                this.rescanButtonText = this.stEditorStrings.gettext_scan_error;
                this.scanningInProgress = false;
                this.state.error = 'Timeout';
                window.YUZ_Assets?.log_colored?.('critical', 'AJAX timeout for gettext scan', { timeout: opts.timeout });
              }
            }
          });
        },
        setExtraText() {
          this.showFiltersAndTable = !( ((!this.upgradedGettext && (this.currentTab.type === 'gettext' || this.currentTab.type === 'emails')) || this.currentTab.type === 'upsale-slugs' || (!['upsale-slugs', 'gettext', 'emails'].includes(this.currentTab.type) && this.noticeUpgradeSlugs)) );
          this.extraText = this.currentTab.type === 'gettext' ? this.noticeUpgradeGettext : this.extraText;
          this.extraText = this.currentTab.type === 'upsale-slugs' ? this.upsaleSlugsText : this.extraText;
          this.extraText = !this.currentTab.type && this.noticeUpgradeSlugs ? this.noticeUpgradeSlugs : this.extraText;
          const query = this.buildQuery(this.filterValues);
          this.clearFilterDisabled = Object.keys(query).length === 0;
          window.YUZ_Assets?.log_colored?.('info', 'Set extra text', { showFiltersAndTable: this.showFiltersAndTable, extraText: this.extraText });
        },
        changeTopofSlugs() {
          if (location.href.match(/#\/slugs/)) { $('.yuz-translation-status-container').css('margin-block-start', '0em'); }
          else { $('.yuz-translation-status-container').css('margin-block-start', '4em'); }
          window.YUZ_Assets?.log_colored?.('info', 'Adjusted slugs margin');
        }
      }
    }
  },

  data() {
    return { dictionary: [], totalItems: null };
  }
};

// ————————————————————————————————————————————————————————————————————————————————
// Router helper (inchangé si tu as déjà buildRoutes(); je le laisse ici pour clarté)
// ————————————————————————————————————————————————————————————————————————————————
function buildRoutes() {
  const routes = [];
  if (window.yuzTraSettings?.string_types_config) {
    for (const yuz_path_index in window.yuzTraSettings.string_types_config) {
      const cfg = window.yuzTraSettings.string_types_config[yuz_path_index];
      if (cfg.category_based) {
        routes.push({
          path: `/${yuz_path_index}/`,
          component: StringTranslation,
          props: {
            translationTab: true,
            translationType: Object.keys(cfg.categories)[0],
            currentTab: cfg.categories[Object.keys(cfg.categories)[0]],
            parentTab: cfg,
            parentTranslationType: yuz_path_index
          }
        });
        for (const cat in cfg.categories) {
          routes.push({
            path: `/${yuz_path_index}/${cat}/`,
            component: StringTranslation,
            props: {
              translationTab: true,
              translationType: cat,
              currentTab: cfg.categories[cat],
              parentTab: cfg,
              parentTranslationType: yuz_path_index
            }
          });
        }
      } else {
        routes.push({
          path: `/${yuz_path_index}/`,
          component: StringTranslation,
          props: {
            translationTab: true,
            translationType: yuz_path_index,
            currentTab: cfg,
            parentTab: false,
            parentTranslationType: false
          }
        });
      }
    }
  }
  if (routes.length > 0) routes.push({ path: '*', component: routes[0].component, props: routes[0].props });
  return routes;
}

// ————————————————————————————————————————————————————————————————————————————————
// Boot robuste (UMD→ESM, WP admin, fallback de rendu)
// ————————————————————————————————————————————————————————————————————————————————
function hardFail(msg, extra = {}) {
  try { window.YUZ_Assets?.log_colored?.('critical', msg, extra); } catch(_) {}
  yuz_release_console.error('[YUZ::fatal]', msg, extra);
  alert('String translation editor cannot initialize.\n\n' + msg);
}

function bootstrap() {
  // Garde-fous essentiels
  if (!Vue)        return hardFail('Vue non chargé (UMD). Vérifie /assets/vendor/vue/vue.min.js');
  if (!VueRouter)  return hardFail('VueRouter non chargé (UMD). Vérifie /assets/vendor/vue-router/vue-router.min.js');
  if (!$)          return hardFail('jQuery non disponible (WP admin devrait l’exposer en window.jQuery)');

  // Interop Vue 2
  try { Vue.use(VueRouter); } catch (e) {
    return hardFail('Vue.use(VueRouter) a échoué — build incompatible ?', { error: e?.message });
  }

  const routes = buildRoutes();
  const router = new VueRouter({
    routes,
    linkExactActiveClass: 'nav-tab-exact-active',
    linkActiveClass: 'nav-tab-active'
  });

  // Conteneur
  let el = document.getElementById('yuz-editor-container');
  if (!el) {
    el = document.createElement('div');
    el.id = 'yuz-editor-container';
    document.body.appendChild(el);
  }

  // Si le tag n’existe pas dans le DOM, on rend via render()
  const mustRender = !el.querySelector('yuz-string-translation');

  try {
    window.YUZ_StringTranslationApp = new Vue({
      el: '#yuz-editor-container',
      router,
      // Utilise render systématiquement : fiable que le tag soit présent ou non
      render: (h) => h(StringTranslation)
    });
    window.YUZ_Assets?.log_colored?.('success', 'YUZ String Translation App mounted');
  } catch (e) {
    return hardFail('Montage Vue a échoué', { error: e?.message, mustRender });
  }
}

// Attend la config WP (localisée par PHP)
function waitForConfig() {
  try { window.YUZ_Assets?.log_colored?.('info', 'waitForConfig'); } catch(_) {}
  if (window.yuzTraSettings && typeof window.yuzTraSettings === 'object') {
    bootstrap();
  } else {
    setTimeout(waitForConfig, 100);
  }
}

waitForConfig();
