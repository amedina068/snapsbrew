import './bootstrap';

import { createApp } from 'vue'

import store from './store'

import mitt from 'mitt'

window.emitter = mitt()

import 'highlight.js/styles/github.css'
import hljs from 'highlight.js/lib/core'
import json from 'highlight.js/lib/languages/json'
import xml from 'highlight.js/lib/languages/xml'
import php from 'highlight.js/lib/languages/php'
import javascript from 'highlight.js/lib/languages/javascript'
import python from 'highlight.js/lib/languages/python'
import ruby from 'highlight.js/lib/languages/ruby'
import http from 'highlight.js/lib/languages/http'

hljs.registerLanguage("json", json);
hljs.registerLanguage("javascript", javascript);
hljs.registerLanguage("xml", xml);
hljs.registerLanguage("php", php);
hljs.registerLanguage("python", python);
hljs.registerLanguage("ruby", ruby);
hljs.registerLanguage("http", http);

window.hljs = hljs

import LayerSidebar from './components/LayerSidebar'
import PrettyJson from './components/PrettyJson'
import ScDropdownMenu from './components/ScDropdownMenu'
import BackgroundImageMediaLibrary from './components/BackgroundImageMediaLibrary'
import LayoutSidebar from './components/LayoutSidebar'
import ScEditor from './components/ScEditor'
import ScEditorTopbar from './components/ScEditorTopbar'
import TemplateActions from './components/TemplateActions'
import TemplateVersions from './components/TemplateVersions'
import CopyTextButton from './components/CopyTextButton'
import EmbeddedForm from './components/EmbeddedForm'
import SbEditorLabel from './components/SbEditorLabel'
import SbEditorInput from './components/SbEditorInput'
import SbEditorSelect from './components/SbEditorSelect'
import SbEditorTextarea from './components/SbEditorTextarea'
import DashboardMobileMenu from './components/DashboardMobileMenu'
import AccountTabs from './components/AccountTabs'
import TemplateActionsModal from './components/TemplateActionsModal'
import TemplateSettingsModal from './components/TemplateSettingsModal'
import CollectionItem from './components/CollectionItem'
import CodeSnippet from './components/CodeSnippet.vue'
import SnapImportUploader from './components/SnapImportUploader'
import SnapImportProgressModal from './components/SnapImportProgressModal'
import OnboardingWizard from './components/OnboardingWizard.vue'
import SnapGenerator from './components/SnapGenerator.vue'
import McpConnect from './components/McpConnect.vue'

const app = createApp({
  components: {
    DashboardMobileMenu,
    BackgroundImageMediaLibrary,
    ScDropdownMenu,
    LayerSidebar,
    LayoutSidebar,
    ScEditor,
    ScEditorTopbar,
    TemplateActions,
    TemplateVersions,
    EmbeddedForm,
    PrettyJson,
    CopyTextButton,
    AccountTabs,
    TemplateActionsModal,
    TemplateSettingsModal,
    CodeSnippet,
    SnapImportUploader,
    SnapImportProgressModal,
    CollectionItem,
    OnboardingWizard,
    SnapGenerator,
    McpConnect
  }
})
app.component('SbEditorLabel', SbEditorLabel)
app.component('SbEditorInput', SbEditorInput)
app.component('SbEditorSelect', SbEditorSelect)
app.component('SbEditorTextarea', SbEditorTextarea)
app.component('TemplateActionsModal', TemplateActionsModal)
app.component('TemplateSettingsModal', TemplateSettingsModal)
app.component('CodeSnippet', CodeSnippet)
app.use(store).mount('#app')
