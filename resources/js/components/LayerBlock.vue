<template>
    <div @mousedown.native="selectLayer" ref="layerBlock" :style="{...styles}" class="bg-cover bg-no-repeat absolute w-full h-full">
      <div :class="[ selected ? 'border-dashed border-gray-300' : 'border-transparent', 'border w-full h-full relative' ]">
        <div
          v-if="selected"
          class="pointer-events-auto absolute -top-12 left-1/2 flex -translate-x-1/2 gap-2 z-10000"
        >
          <button
            type="button"
            class="inline-flex items-center rounded-full bg-white px-2 py-1 text-xs font-medium text-gray-600 shadow-sm transition hover:bg-indigo-50 hover:text-indigo-600"
            @click.stop="bringForward"
            :disabled="!canBringForward"
            :class="canBringForward ? '' : 'cursor-not-allowed opacity-50'"
            aria-label="Bring layer forward"
            data-type="control"
          >
            <ArrowUpIcon class="mr-1 h-4 w-4" />
            Front
          </button>
          <button
            type="button"
            class="inline-flex items-center rounded-full bg-white px-2 py-1 text-xs font-medium text-gray-600 shadow-sm transition hover:bg-indigo-50 hover:text-indigo-600"
            @click.stop="sendBackward"
            :disabled="!canSendBackward"
            :class="canSendBackward ? '' : 'cursor-not-allowed opacity-50'"
            aria-label="Send layer backward"
            data-type="control"
          >
            <ArrowDownIcon class="mr-1 h-4 w-4" />
            Back
          </button>
          <button
            type="button"
            class="inline-flex items-center rounded-full bg-white px-2 py-1 text-xs font-medium text-gray-600 shadow-sm transition hover:bg-indigo-50 hover:text-indigo-600"
            @click.stop="toggleLock"
            aria-label="Toggle layer lock"
            data-type="control"
          >
            <LockClosedIcon
              v-if="locked"
              class="mr-1 h-4 w-4"
            />
            <LockOpenIcon
              v-else
              class="mr-1 h-4 w-4"
            />
            {{ locked ? 'Unlock' : 'Lock' }}
          </button>
          <button
            type="button"
            class="inline-flex items-center rounded-full bg-white px-2 py-1 text-xs font-medium text-gray-600 shadow-sm transition hover:bg-indigo-50 hover:text-indigo-600"
            @click.stop="duplicateLayer"
            :disabled="locked"
            :class="locked ? 'cursor-not-allowed opacity-50' : ''"
            aria-label="Duplicate layer"
            data-type="control"
          >
            <DocumentDuplicateIcon class="mr-1 h-4 w-4" />
            Duplicate
          </button>
        </div>
        <div
          v-show="selected && ! locked"
          ref="resizerTL"
          data-type="resizer"
          class="cursor-move h-3 w-3 bg-gray-200 border border-gray-300 absolute top-0 left-0 -ml-2 -mt-2 z-30 pointer-events-auto"
        ></div>
        <div
          v-show="selected && ! locked && ! aspectRatio"
          ref="resizerL"
          data-type="resizer"
          class="cursor-move h-3 w-3 bg-gray-200 border border-gray-300 absolute my-auto inset-y-0 left-0 -ml-2 z-30 pointer-events-auto"
        ></div>
        <div
          v-show="selected && ! locked"
          ref="resizerTR"
          data-type="resizer"
          class="cursor-move h-3 w-3 bg-gray-200 border border-gray-300 absolute top-0 right-0 -mr-2 -mt-2 z-30 pointer-events-auto"
        ></div>
        <div
          v-show="selected && ! locked"
          ref="resizerBL"
          data-type="resizer"
          class="cursor-move h-3 w-3 bg-gray-200 border border-gray-300 absolute bottom-0 left-0 -mb-2 -ml-2 z-30 pointer-events-auto"
        ></div>
        <div
          v-show="selected && ! locked && ! aspectRatio"
          ref="resizerT"
          data-type="resizer"
          class="cursor-move h-3 w-3 bg-gray-200 border border-gray-300 absolute mx-auto inset-x-0 top-0 -mt-2 z-30 pointer-events-auto"
        ></div>
        <div
          v-show="selected && ! locked && ! aspectRatio"
          ref="resizerB"
          data-type="resizer"
          class="cursor-move h-3 w-3 bg-gray-200 border border-gray-300 absolute mx-auto inset-x-0 bottom-0 -mb-2 z-30 pointer-events-auto"
        ></div>
        <div
          v-show="selected && ! locked"
          ref="resizerBR"
          data-type="resizer"
          class="cursor-move h-3 w-3 bg-gray-200 border border-gray-300 absolute bottom-0 right-0 -mb-2 -mr-2 z-30 pointer-events-auto"
        ></div>
        <div
          v-show="selected && ! locked && ! aspectRatio"
          ref="resizerR"
          data-type="resizer"
          class="cursor-move h-3 w-3 bg-gray-200 border border-gray-300 absolute my-auto inset-y-0 right-0 -mr-2 z-30 pointer-events-auto"
        ></div>
        <template v-if="layer.type === 'text'">
          <span>{{ textContent }}</span>
        </template>
        <template v-else-if="isQrLayer">
          <div class="flex h-full w-full flex-col items-center justify-center gap-1 rounded-sm border border-dashed border-slate-400/70 bg-white/80 px-3 text-center">
            <span class="text-[10px] font-semibold tracking-wide text-slate-600">
              QR CODE ({{ qrContentTypeLabel }})
            </span>
            <span class="text-[11px] text-slate-500" style="word-break: break-word;">
              {{ qrContent || 'No content' }}
            </span>
          </div>
        </template>
        <template v-else-if="isBarcodeLayer">
          <div class="flex h-full w-full flex-col items-center justify-center gap-1 rounded-sm border border-dashed border-slate-400/70 bg-white/80 px-3 text-center">
            <span class="text-[10px] font-semibold tracking-wide text-slate-600">
              BARCODE ({{ barcodeFormatLabel }})
            </span>
            <span class="text-[11px] text-slate-500" style="word-break: break-word;">
              {{ barcodeContent || 'No content' }}
            </span>
          </div>
        </template>
      </div>
    </div>  
</template>

<script setup>
  import { useStore } from 'vuex'
  import { ArrowDownIcon, ArrowUpIcon } from '@heroicons/vue/20/solid'
  import { LockClosedIcon, LockOpenIcon, DocumentDuplicateIcon } from '@heroicons/vue/24/outline'
  import { orderBy } from 'lodash'
  import { computed, toRefs, ref, onMounted, watch, nextTick } from 'vue'
  const props = defineProps({
    layer: {
      type: Object,
      required: true,
    }
  })

  const store = useStore()

  const { layer } = toRefs(props)

  const layerBlock = ref(null)
  const resizerTL = ref(null)
  const resizerTR = ref(null)
  const resizerBL = ref(null)
  const resizerBR = ref(null)
  const resizerR = ref(null)
  const resizerB = ref(null)
  const resizerL = ref(null)
  const resizerT = ref(null)

  const snapToGrid = computed(() => store.state.snapToGrid)
  const gridSize = 15

  const snapToGridValue = (value) => {
    return Math.round(value / gridSize) * gridSize;
  }

  const setUndo = properties => store.commit('setUndo', properties)

  const registerEventListeners = () => {
    let initialMouseX, initialMouseY, initialLeft, initialTop, initialWidth, initialHeight;
    let finalLeft, finalTop, finalWidth, finalHeight;
    let isDragging = false;
    let isResizing = false;

    const updateElementStyle = (left, top, width, height) => {
      if (!layerBlock.value) return;
      layerBlock.value.style.left = `${left * calculatedZoom.value}px`;
      layerBlock.value.style.top = `${top * calculatedZoom.value}px`;
      layerBlock.value.style.width = `${width * calculatedZoom.value}px`;
      layerBlock.value.style.height = `${height * calculatedZoom.value}px`;
    }

    const handleDragMove = e => {
      if (!isDragging) return;
      e.preventDefault();

      let currentLeft = initialLeft + (e.clientX - initialMouseX) / calculatedZoom.value;
      let currentTop = initialTop + (e.clientY - initialMouseY) / calculatedZoom.value;

      if (snapToGrid.value) {
        currentLeft = snapToGridValue(currentLeft);
        currentTop = snapToGridValue(currentTop);
      }

      finalLeft = Math.round(currentLeft);
      finalTop = Math.round(currentTop);
      updateElementStyle(finalLeft, finalTop, initialWidth, initialHeight); 
    }

    const handleDragEnd = e => {
      if (!isDragging) return;
      isDragging = false;
      document.removeEventListener('mousemove', handleDragMove);
      document.removeEventListener('mouseup', handleDragEnd);

      if (finalLeft !== initialLeft || finalTop !== initialTop) {
        const undoActions = [];
        const redoActions = [];
        if (finalLeft !== initialLeft) {
          undoActions.push({ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'positionX', propertyValue: initialLeft } })
          redoActions.push({ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'positionX', propertyValue: finalLeft } })
          store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: 'positionX', propertyValue: finalLeft })
        }
        if (finalTop !== initialTop) {
          undoActions.push({ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'positionY', propertyValue: initialTop } })
          redoActions.push({ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'positionY', propertyValue: finalTop } })
          store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: 'positionY', propertyValue: finalTop })
        }
        setUndo({ undoActions, redoActions })
      }
      else {
        updateElementStyle(initialLeft, initialTop, initialWidth, initialHeight);
      }
    }

    layerBlock.value.addEventListener('mousedown', e => {
      if (locked.value || e.target.dataset.type === 'resizer' || e.target.dataset.type === 'control') return;
      e.stopPropagation();
      e.preventDefault();

      isDragging = true;
      initialMouseX = e.clientX;
      initialMouseY = e.clientY;
      initialLeft = left.value; 
      initialTop = top.value;
      initialWidth = width.value;
      initialHeight = height.value;
      finalLeft = initialLeft;
      finalTop = initialTop;

      document.addEventListener('mousemove', handleDragMove);
      document.addEventListener('mouseup', handleDragEnd);
    })

    const handleResizeMove = (e, resizeFn) => {
      if (!isResizing) return;
      e.preventDefault();
      const newState = resizeFn(e, initialMouseX, initialMouseY, initialLeft, initialTop, initialWidth, initialHeight);
      finalLeft = newState.left;
      finalTop = newState.top;
      finalWidth = newState.width;
      finalHeight = newState.height;
      updateElementStyle(finalLeft, finalTop, finalWidth, finalHeight);
    }

    const handleResizeEnd = e => {
      if (!isResizing) return;
      isResizing = false;
      document.removeEventListener('mousemove', boundResizeMove);
      document.removeEventListener('mouseup', handleResizeEnd);

      if (finalLeft !== initialLeft || finalTop !== initialTop || finalWidth !== initialWidth || finalHeight !== initialHeight) {
        const undoActions = [];
        const redoActions = [];
        if (finalWidth !== initialWidth) {
          undoActions.push({ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'width', propertyValue: initialWidth } })
          redoActions.push({ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'width', propertyValue: finalWidth } })
          store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: 'width', propertyValue: finalWidth })
        }
         if (finalHeight !== initialHeight) {
          undoActions.push({ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'height', propertyValue: initialHeight } })
          redoActions.push({ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'height', propertyValue: finalHeight } })
          store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: 'height', propertyValue: finalHeight })
        }
         if (finalLeft !== initialLeft) {
          undoActions.push({ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'positionX', propertyValue: initialLeft } })
          redoActions.push({ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'positionX', propertyValue: finalLeft } })
          store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: 'positionX', propertyValue: finalLeft })
        }
        if (finalTop !== initialTop) {
          undoActions.push({ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'positionY', propertyValue: initialTop } })
          redoActions.push({ action: 'updateLayerProperty', payload: { layerId: layer.value.id, propertyName: 'positionY', propertyValue: finalTop } })
          store.commit('updateLayerProperty', { layerId: layer.value.id, propertyName: 'positionY', propertyValue: finalTop })
        }
        setUndo({ undoActions, redoActions })
      }
      else {
        updateElementStyle(initialLeft, initialTop, initialWidth, initialHeight);
      }
    }
    
    let boundResizeMove = null;

    const setupResizer = (resizerRef, resizeCalculationFn) => {
        if (!resizerRef.value) return;
        resizerRef.value.addEventListener('mousedown', e => {
            if (locked.value) return;
            e.stopPropagation();
            e.preventDefault();

            isResizing = true;
            initialMouseX = e.clientX;
            initialMouseY = e.clientY;
            initialLeft = left.value;
            initialTop = top.value;
            initialWidth = width.value;
            initialHeight = height.value;
            finalLeft = initialLeft;
            finalTop = initialTop;
            finalWidth = initialWidth;
            finalHeight = initialHeight;

            boundResizeMove = (event) => handleResizeMove(event, resizeCalculationFn);

            document.addEventListener('mousemove', boundResizeMove);
            document.addEventListener('mouseup', handleResizeEnd);
        });
    }

    const calcResizeBottomRight = (e, iMX, iMY, iL, iT, iW, iH) => {
      const mouseDeltaX = e.clientX - iMX;
      const mouseDeltaY = e.clientY - iMY;
      let newW = iW + (mouseDeltaX / calculatedZoom.value);
      let newH = iH + (mouseDeltaY / calculatedZoom.value);
      if (aspectRatio.value) newH = newW / aspectRatio.value;
      if (snapToGrid.value) {
        newW = snapToGridValue(newW);
        if (aspectRatio.value) newH = newW / aspectRatio.value;
        else newH = snapToGridValue(newH);
        newW = Math.max(newW, gridSize); newH = Math.max(newH, gridSize);
      }
      return { left: iL, top: iT, width: Math.round(newW), height: Math.round(newH) };
    };
    const calcResizeBottom = (e, iMX, iMY, iL, iT, iW, iH) => {
      const mouseDeltaY = e.clientY - iMY;
      let newH = iH + (mouseDeltaY / calculatedZoom.value);
      if (snapToGrid.value) { newH = snapToGridValue(newH); newH = Math.max(newH, gridSize); }
      return { left: iL, top: iT, width: iW, height: Math.round(newH) };
    };
     const calcResizeRight = (e, iMX, iMY, iL, iT, iW, iH) => {
      const mouseDeltaX = e.clientX - iMX;
      let newW = iW + (mouseDeltaX / calculatedZoom.value);
      if (snapToGrid.value) { newW = snapToGridValue(newW); newW = Math.max(newW, gridSize); }
      return { left: iL, top: iT, width: Math.round(newW), height: iH };
    };
     const calcResizeTopRight = (e, iMX, iMY, iL, iT, iW, iH) => {
      const mouseDeltaX = e.clientX - iMX;
      const mouseDeltaY = e.clientY - iMY;
      let targetX = iL + iW + (mouseDeltaX / calculatedZoom.value);
      let targetY = iT + (mouseDeltaY / calculatedZoom.value);
      if (snapToGrid.value) { targetX = snapToGridValue(targetX); targetY = snapToGridValue(targetY); }
      let newW = targetX - iL;
      let newH = (iT + iH) - targetY;
      let newT = targetY;
      if (aspectRatio.value) {
        newH = newW / aspectRatio.value;
        newT = (iT + iH) - newH;
        if (snapToGrid.value) { newT = snapToGridValue(newT); newH = (iT + iH) - newT; }
      }
      if (snapToGrid.value) { newW = Math.max(newW, gridSize); newH = Math.max(newH, gridSize); newT = (iT + iH) - newH; }
      return { left: iL, top: Math.round(newT), width: Math.round(newW), height: Math.round(newH) };
    };
    const calcResizeBottomLeft = (e, iMX, iMY, iL, iT, iW, iH) => {
       const mouseDeltaX = e.clientX - iMX;
      const mouseDeltaY = e.clientY - iMY;
      let targetX = iL + (mouseDeltaX / calculatedZoom.value);
      let targetY = iT + iH + (mouseDeltaY / calculatedZoom.value);
      if (snapToGrid.value) { targetX = snapToGridValue(targetX); targetY = snapToGridValue(targetY); }
      let newW = (iL + iW) - targetX;
      let newH = targetY - iT;
      let newL = targetX;
      if (aspectRatio.value) {
        newH = newW / aspectRatio.value;
         if (snapToGrid.value) { newH = snapToGridValue(newH); }
      }
       if (snapToGrid.value) { newW = Math.max(newW, gridSize); newH = Math.max(newH, gridSize); newL = (iL + iW) - newW; }
      return { left: Math.round(newL), top: iT, width: Math.round(newW), height: Math.round(newH) };
    };
     const calcResizeTopLeft = (e, iMX, iMY, iL, iT, iW, iH) => {
      const mouseDeltaX = e.clientX - iMX;
      const mouseDeltaY = e.clientY - iMY;
      let targetX = iL + (mouseDeltaX / calculatedZoom.value);
      let targetY = iT + (mouseDeltaY / calculatedZoom.value);
      if (snapToGrid.value) { targetX = snapToGridValue(targetX); targetY = snapToGridValue(targetY); }
      let newW = (iL + iW) - targetX;
      let newH = (iT + iH) - targetY;
      let newL = targetX;
      let newT = targetY;
      if (aspectRatio.value) {
        newH = newW / aspectRatio.value;
        newT = (iT + iH) - newH;
        if (snapToGrid.value) { newT = snapToGridValue(newT); newH = (iT + iH) - newT; }
      }
      if (snapToGrid.value) { 
          newW = Math.max(newW, gridSize); newH = Math.max(newH, gridSize); 
          newL = (iL + iW) - newW; newT = (iT + iH) - newH;
      } 
      return { left: Math.round(newL), top: Math.round(newT), width: Math.round(newW), height: Math.round(newH) };
    };
     const calcResizeTop = (e, iMX, iMY, iL, iT, iW, iH) => {
      const mouseDeltaY = e.clientY - iMY;
      let newT = iT + (mouseDeltaY / calculatedZoom.value);
      let newH = iH - (mouseDeltaY / calculatedZoom.value);
      if (snapToGrid.value) { newT = snapToGridValue(newT); newH = snapToGridValue((iT + iH) - newT); newH = Math.max(newH, gridSize); newT = (iT + iH) - newH; }
      return { left: iL, top: Math.round(newT), width: iW, height: Math.round(newH) };
    };
     const calcResizeLeft = (e, iMX, iMY, iL, iT, iW, iH) => {
      const mouseDeltaX = e.clientX - iMX;
      let newL = iL + (mouseDeltaX / calculatedZoom.value);
      let newW = iW - (mouseDeltaX / calculatedZoom.value);
       if (snapToGrid.value) { newL = snapToGridValue(newL); newW = snapToGridValue((iL + iW) - newL); newW = Math.max(newW, gridSize); newL = (iL + iW) - newW; }
      return { left: Math.round(newL), top: iT, width: Math.round(newW), height: iH };
    };

    setupResizer(resizerBR, calcResizeBottomRight);
    setupResizer(resizerB, calcResizeBottom);
    setupResizer(resizerR, calcResizeRight);
    setupResizer(resizerTR, calcResizeTopRight);
    setupResizer(resizerBL, calcResizeBottomLeft);
    setupResizer(resizerTL, calcResizeTopLeft);
    setupResizer(resizerT, calcResizeTop);
    setupResizer(resizerL, calcResizeLeft);
    
  }

  onMounted(() => {
    if (layerBlock.value) {
       registerEventListeners();
    } else {
        nextTick(() => {
            if (layerBlock.value) registerEventListeners();
        });
    }
  });

  let mouseXPosition = null
  let mouseYPosition = null

  const selectedLayer = computed(() => store.state.selectedLayer)
  const selected = computed(() => selectedLayer.value ? selectedLayer.value.id == layer.value.id : false)
  const locked = computed(() => layer.value.locked)
  const calculatedZoom = computed(() => store.state.calculatedZoom)
  const width = computed(() => store.getters.property(layer.value.id, 'width').value)
  const height = computed(() => store.getters.property(layer.value.id, 'height').value)
  const left = computed(() => store.getters.property(layer.value.id, 'positionX').value)
  const top = computed(() => store.getters.property(layer.value.id, 'positionY').value)
  const textContent = computed(() => store.getters.property(layer.value.id, 'textContent').value)
  const qrContent = computed(() => store.getters.property(layer.value.id, 'qrContent').value || '')
  const qrContentType = computed(() => store.getters.property(layer.value.id, 'qrContentType').value || 'url')
  const qrContentTypeLabel = computed(() => (qrContentType.value || 'url').toUpperCase())
  const fontSize = computed(() => store.getters.property(layer.value.id, 'fontSize').value)
  const fontFamily = computed(() => store.getters.property(layer.value.id, 'fontFamily').value)
  const fontColor = computed(() => store.getters.property(layer.value.id, 'fontColor').value)
  const textAlignment = computed(() => store.getters.property(layer.value.id, 'textAlignment').value)
  const lineHeight = computed(() => (store.getters.property(layer.value.id, 'lineHeight').value ?? 1.2 * fontSize.value))
  const borderRadiusLeftTop = computed(() => store.getters.property(layer.value.id, 'borderRadiusLeftTop').value)
  const borderRadiusRightTop = computed(() => store.getters.property(layer.value.id, 'borderRadiusRightTop').value)
  const borderRadiusLeftBottom = computed(() => store.getters.property(layer.value.id, 'borderRadiusLeftBottom').value)
  const shadow = computed(() => store.getters.property(layer.value.id, 'shadow-sm').value)
  const borderStyle = computed(() => store.getters.property(layer.value.id, 'borderStyle').value)
  const borderWidth = computed(() => store.getters.property(layer.value.id, 'borderWidth').value)
  const borderColor = computed(() => store.getters.property(layer.value.id, 'borderColor').value)
  const borderRadiusRightBottom = computed(() => store.getters.property(layer.value.id, 'borderRadiusRightBottom').value)
  const zIndex = computed(() => store.getters.property(layer.value.id, 'zIndex').value)
  const aspectRatio = computed(() => store.getters.property(layer.value.id, 'aspectRatio').value)
  const rotate = computed(() => store.getters.property(layer.value.id, 'rotate').value)
  const flipHorizontal = computed(() => store.getters.property(layer.value.id, 'flipHorizontal').value === true)
  const flipVertical = computed(() => store.getters.property(layer.value.id, 'flipVertical').value === true)
  const mixBlendMode = computed(() => store.getters.property(layer.value.id, 'mixBlendMode').value || 'normal')

  // Single transform chokepoint: rotate + both flips compose into ONE string so
  // a flip never overwrites rotate (the render path does the same).
  const computedTransform = computed(() => {
    const parts = []
    if (rotate.value) parts.push(`rotate(${rotate.value}deg)`)
    if (flipHorizontal.value) parts.push('scaleX(-1)')
    if (flipVertical.value) parts.push('scaleY(-1)')
    return parts.join(' ')
  })

  const FILTER_SPECS = [
    { name: 'filterBlur', fn: 'blur-sm', unit: 'px', identity: 0 },
    { name: 'filterBrightness', fn: 'brightness', unit: '', identity: 1 },
    { name: 'filterContrast', fn: 'contrast', unit: '', identity: 1 },
    { name: 'filterSaturate', fn: 'saturate', unit: '', identity: 1 },
    { name: 'filterGrayscale', fn: 'grayscale', unit: '', identity: 0 },
  ]
  const computedFilter = computed(() => FILTER_SPECS.reduce((parts, spec) => {
    const value = store.getters.property(layer.value.id, spec.name).value
    if (value === undefined || value === null || value === '' || Number(value) === spec.identity) {
      return parts
    }
    parts.push(`${spec.fn}(${Number(value)}${spec.unit})`)
    return parts
  }, []).join(' '))

  const opacity = computed(() => {
    const value = store.getters.property(layer.value.id, 'opacity').value
    return value === undefined || value === null ? 1 : value
  })
  const layers = computed(() => store.state.layers)
  const orderedLayers = computed(() => orderBy(
    layers.value.map(layerItem => ({
      id: layerItem.id,
      zIndex: layerItem.properties.find(property => property.name === 'zIndex').value
    })),
    'zIndex',
    'desc'
  ))
  const currentLayerIndex = computed(() => orderedLayers.value.findIndex(item => item.id === layer.value.id))
  const canBringForward = computed(() => currentLayerIndex.value > 0)
  const canSendBackward = computed(() => currentLayerIndex.value >= 0 && currentLayerIndex.value < orderedLayers.value.length - 1)

  const applyOrder = orderedItems => {
    const newLayers = orderedItems.map((orderedItem, index) => {
      const originalLayer = layers.value.find(item => item.id === orderedItem.id)
      if (!originalLayer) {
        return null
      }

      const properties = originalLayer.properties.map(property => {
        if (property.name === 'zIndex') {
          return {
            ...property,
            value: layers.value.length - index
          }
        }
        return property
      })

      return {
        ...originalLayer,
        properties
      }
    }).filter(Boolean)

    store.commit('setLayers', newLayers)
    store.commit('selectLayer', layer.value.id)
  }

  const bringForward = () => {
    if (locked.value || !canBringForward.value) {
      return
    }

    const newOrder = [...orderedLayers.value]
    const index = currentLayerIndex.value
    ;[newOrder[index - 1], newOrder[index]] = [newOrder[index], newOrder[index - 1]]
    applyOrder(newOrder)
  }

  const sendBackward = () => {
    if (locked.value || !canSendBackward.value) {
      return
    }

    const newOrder = [...orderedLayers.value]
    const index = currentLayerIndex.value
    ;[newOrder[index], newOrder[index + 1]] = [newOrder[index + 1], newOrder[index]]
    applyOrder(newOrder)
  }

  const toggleLock = () => store.commit('updateLayer', {
    name: 'locked',
    layerId: layer.value.id,
    value: ! locked.value
  })

  const duplicateLayer = () => {
    if (locked.value) {
      return
    }

    const layerData = layers.value.find(item => item.id === layer.value.id)
    if (!layerData) {
      return
    }

    const newLayer = {
      ...layerData,
      id: `id-${parseInt(Math.random() * 1000)}`,
      title: `${layerData.title} clone`,
      key: `layer_${layers.value.length + 1}`,
      properties: layerData.properties.map(property => property.name !== 'zIndex' ? property : { name: 'zIndex', value: layers.value.length + 1 })
    }

    store.commit('addLayer', newLayer)
    store.commit('selectLayer', newLayer.id)
  }

  const backgroundStyles = computed(() => store.getters.backgroundReadableStyles(layer.value.id))
  const isQrLayer = computed(() => layer.value.type === 'qr_code')
  const isBarcodeLayer = computed(() => layer.value.type === 'barcode')
  const barcodeContent = computed(() => store.getters.property(layer.value.id, 'barcodeContent').value || '')
  const barcodeFormatLabel = computed(() => (store.getters.property(layer.value.id, 'barcodeFormat').value || 'Code128'))

  const styles = computed(() => ({
    ...backgroundStyles.value,
    borderTopLeftRadius: `${calculatedZoom.value * borderRadiusLeftTop.value}px`,
    borderTopRightRadius: `${calculatedZoom.value * borderRadiusRightTop.value}px`,
    borderBottomLeftRadius: `${calculatedZoom.value * borderRadiusLeftBottom.value}px`,
    borderBottomRightRadius: `${calculatedZoom.value * borderRadiusRightBottom.value}px`,
    boxShadow: shadow.value ? `${calculatedZoom.value * shadow.value.x}px ${calculatedZoom.value * shadow.value.y}px ${calculatedZoom.value * shadow.value.blur}px ${calculatedZoom.value * shadow.value.spread}px ${shadow.value.color}` : '',
    fontSize: `${calculatedZoom.value * fontSize.value}px`,
    fontFamily: fontFamily.value,
    lineHeight: `${calculatedZoom.value * lineHeight.value}px`,
    transform: computedTransform.value || undefined,
    filter: computedFilter.value || undefined,
    mixBlendMode: mixBlendMode.value,
    opacity: opacity.value,
    height: `${calculatedZoom.value * height.value}px`,
    width: `${calculatedZoom.value * width.value}px`,
    color: fontColor.value,
    borderStyle: borderStyle.value,
    borderWidth: `${calculatedZoom.value * borderWidth.value}px`,
    borderColor: borderColor.value,
    left: `${calculatedZoom.value * left.value}px`,
    top: `${calculatedZoom.value * top.value}px`,
    textAlign: textAlignment.value,
    whiteSpace: 'normal',
    zIndex: zIndex.value,
    backgroundPosition: 'center'
  }))

  const selectLayer = () => selected.value ? null : store.commit('selectLayer', layer.value.id)
</script>
