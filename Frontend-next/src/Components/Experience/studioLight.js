import { Mesh, OrthographicCamera, PMREMGenerator } from 'three'

/*
 * THE STUDIO MAP, FILTERED WITHOUT FREEZING THE PAGE (2026-11-06, Watchizer only).
 *
 * three turns the studio HDR into the map a material reflects (a "PMREM") the first time a material that uses it is
 * compiled — synchronously: it creates the filter's shaders and uses them in the same call, so the page's main
 * thread waits for the graphics driver to compile and link them. Measured on the home, 2026-11-06: ONE TASK OF
 * 0.9–1.1 s on a computer with an Intel UHD (Direct3D), 0.9 s on the throttled phone profile — the home's whole
 * blocking time on a computer, after the page had loaded.
 *
 * Here the SAME generator (three's own PMREMGenerator, same code, same input, same output — nothing the visitor
 * sees changes) is driven in two steps: its shaders are handed to the driver first and awaited (the browser
 * compiles them in its graphics process: KHR_parallel_shader_compile), THEN the filter runs with programs that are
 * already linked. A material given the result (a CubeUV map) is not converted again by three.
 *
 * Measured on a bare page with this file's steps (2026-11-06, Intel UHD, Direct3D): the filter call blocks for
 * 760 ms as three runs it, 19 ms here. A program is keyed by the geometry it is compiled with as well as by its
 * shaders — compiled against an empty geometry the filter's programs were compiled a second time at first use
 * (558 ms), so each is compiled with the generator's own plane.
 *
 * The names read below (`_setSize`, `_allocateTargets`, `_lodMeshes`, `_ggxMaterial`, `_equirectMaterial`) are
 * three's private ones, as of 0.185: if a later three renames them the `try` ends, and the generator runs exactly
 * as three would have run it — the page waits as it did before, nothing breaks.
 */
const flat = new OrthographicCamera()

/**
 * @param {import('three').WebGLRenderer} gl
 * @param {import('three').Texture} equirect the studio HDR as loaded (equirectangular)
 * @returns {Promise<import('three').WebGLRenderTarget>} the filtered map; `.texture` goes to scene.environment /
 *   material.envMap, and the caller disposes the target
 */
export async function prefilterEnvironment(gl, equirect) {
  const generator = new PMREMGenerator(gl)
  try {
    const previous = gl.getRenderTarget()
    try {
      generator._setSize(equirect.image.width / 4)
      const scratch = generator._allocateTargets() // also creates the filter's material at this size
      // a program is keyed by where it draws (a target's colour space, no tone mapping): compile against one
      gl.setRenderTarget(scratch)
      generator.compileEquirectangularShader()
      const planes = generator._lodMeshes || []
      const waits = [
        [generator._equirectMaterial, planes[0]], // the HDR onto the cube's faces
        [generator._ggxMaterial, planes[1]], // each rougher level from the one before
      ]
        .filter(([material, plane]) => material && plane)
        .map(([material, plane]) => gl.compileAsync(new Mesh(plane.geometry, material), flat))
      gl.setRenderTarget(previous)
      scratch.dispose()
      await Promise.all(waits)
    } catch {
      gl.setRenderTarget(previous)
    }
    return generator.fromEquirectangular(equirect)
  } finally {
    generator.dispose()
  }
}
