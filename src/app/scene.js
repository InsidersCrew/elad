/** Renderer, camera, lights, bloom, controls and the frame loop. */
import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { EffectComposer } from 'three/examples/jsm/postprocessing/EffectComposer.js';
import { RenderPass } from 'three/examples/jsm/postprocessing/RenderPass.js';
import { UnrealBloomPass } from 'three/examples/jsm/postprocessing/UnrealBloomPass.js';
import { OutputPass } from 'three/examples/jsm/postprocessing/OutputPass.js';
import { tween, ease, updateTweens } from './tween.js';

export const BG = 0x05142f;

export function createScene(canvas) {
  const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, powerPreference: 'high-performance' });
  renderer.setClearColor(BG, 1);
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  renderer.toneMappingExposure = 1.05;
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  renderer.shadowMap.enabled = true;
  renderer.shadowMap.type = THREE.PCFSoftShadowMap;

  const scene = new THREE.Scene();
  scene.background = new THREE.Color(BG);
  scene.fog = new THREE.Fog(BG, 120, 320);

  const camera = new THREE.PerspectiveCamera(40, 1, 0.5, 600);
  camera.position.set(6, 72, 112);

  const controls = new OrbitControls(camera, canvas);
  controls.enableDamping = true;
  controls.dampingFactor = 0.07;
  controls.minDistance = 22;
  controls.maxDistance = 200;
  controls.maxPolarAngle = THREE.MathUtils.degToRad(80);
  controls.minPolarAngle = THREE.MathUtils.degToRad(12);
  controls.target.set(0, 0, 0);
  controls.enablePan = true;
  controls.panSpeed = 0.6;
  controls.screenSpacePanning = false;

  // Lights: cool hemisphere + warm key light + fill
  scene.add(new THREE.HemisphereLight(0x2f5fb3, 0x05142f, 0.85));
  const key = new THREE.DirectionalLight(0xdfe9ff, 1.25);
  key.position.set(60, 110, 40);
  key.castShadow = true;
  key.shadow.mapSize.set(2048, 2048);
  key.shadow.camera.left = -90;
  key.shadow.camera.right = 90;
  key.shadow.camera.top = 90;
  key.shadow.camera.bottom = -90;
  key.shadow.camera.near = 10;
  key.shadow.camera.far = 300;
  key.shadow.bias = -0.0008;
  scene.add(key);
  const fill = new THREE.DirectionalLight(0x460fff, 0.28);
  fill.position.set(-80, 40, -60);
  scene.add(fill);

  // Ground + grid
  const ground = new THREE.Mesh(new THREE.PlaneGeometry(900, 900), new THREE.MeshStandardMaterial({ color: 0x07183a, roughness: 1, metalness: 0 }));
  ground.rotation.x = -Math.PI / 2;
  ground.position.y = -0.02;
  ground.receiveShadow = true;
  scene.add(ground);
  const grid = new THREE.GridHelper(600, 100, 0x123a7a, 0x0c2a5c);
  grid.material.transparent = true;
  grid.material.opacity = 0.28;
  grid.position.y = 0;
  scene.add(grid);

  // Post-processing (bloom) — selectable quality
  const composer = new EffectComposer(renderer);
  composer.addPass(new RenderPass(scene, camera));
  const bloom = new UnrealBloomPass(new THREE.Vector2(1, 1), 0.55, 0.55, 0.78);
  composer.addPass(bloom);
  composer.addPass(new OutputPass());

  const state = { bloom: true, running: true, frame: [] };
  const mq = window.matchMedia('(max-width: 900px)');
  state.bloom = !mq.matches;

  function resize() {
    const w = canvas.clientWidth || window.innerWidth;
    const h = canvas.clientHeight || window.innerHeight;
    const dpr = Math.min(window.devicePixelRatio || 1, state.bloom ? 1.5 : 2);
    renderer.setPixelRatio(dpr);
    renderer.setSize(w, h, false);
    composer.setPixelRatio(dpr);
    composer.setSize(w, h);
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
  }
  window.addEventListener('resize', resize);
  resize();

  const clock = new THREE.Clock();
  let raf = 0;
  function loop() {
    raf = requestAnimationFrame(loop);
    if (!state.running) return;
    const dt = Math.min(0.05, clock.getDelta());
    const now = performance.now();
    updateTweens(now);
    controls.update();
    for (const fn of state.frame) fn(dt, now);
    if (state.bloom) composer.render();
    else renderer.render(scene, camera);
  }
  loop();
  document.addEventListener('visibilitychange', () => {
    state.running = !document.hidden;
    if (state.running) clock.getDelta();
  });

  /** Smoothly move the camera to look at `target` from `distance`. */
  function flyTo(target, distance = 60, duration = 1100, elevation = 0.62) {
    const fromPos = camera.position.clone();
    const fromTarget = controls.target.clone();
    const dir = fromPos.clone().sub(fromTarget).setY(0);
    if (dir.lengthSq() < 1e-3) dir.set(0, 0, 1);
    dir.normalize();
    const toPos = target.clone().add(dir.multiplyScalar(distance * Math.cos(elevation))).add(new THREE.Vector3(0, distance * Math.sin(elevation), 0));
    tween({
      key: 'camera',
      duration,
      ease: ease.inOut,
      onUpdate: (k) => {
        camera.position.lerpVectors(fromPos, toPos, k);
        controls.target.lerpVectors(fromTarget, target, k);
      },
    });
  }

  function resetView(duration = 1100) {
    const toPos = new THREE.Vector3(6, 72, 112);
    const fromPos = camera.position.clone();
    const fromTarget = controls.target.clone();
    tween({ key: 'camera', duration, ease: ease.inOut, onUpdate: (k) => { camera.position.lerpVectors(fromPos, toPos, k); controls.target.lerpVectors(fromTarget, new THREE.Vector3(0, 0, 0), k); } });
  }

  function setBloom(on) {
    state.bloom = on;
    resize();
  }

  return {
    renderer, scene, camera, controls, composer, bloom, state,
    onFrame: (fn) => state.frame.push(fn),
    flyTo, resetView, setBloom, resize,
    dispose: () => cancelAnimationFrame(raf),
  };
}
