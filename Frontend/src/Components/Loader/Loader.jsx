import { motion } from 'framer-motion';

const Loader = () => {
  return (
    <div style={{
      position: 'fixed',
      inset: 0,
      display: 'flex',
      flexDirection: 'column',
      alignItems: 'center',
      justifyContent: 'center',
      backgroundColor: '#0f0f0f',
      zIndex: 9999,
    }}>

      {/* Top gold line */}
      <div style={{
        position: 'absolute',
        top: 0, left: 0, right: 0,
        height: '2px',
        background: 'linear-gradient(90deg, transparent, #C8A45C, transparent)',
      }} />

      {/* Watch container */}
      <div style={{ position: 'relative', width: 160, height: 160, marginBottom: 32 }}>

        {/* Outer rotating dashed ring */}
        <motion.div
          style={{
            position: 'absolute',
            inset: -12,
            borderRadius: '50%',
            border: '1px dashed rgba(200,164,92,0.5)',
          }}
          animate={{ rotate: 360 }}
          transition={{ duration: 8, repeat: Infinity, ease: 'linear' }}
        />

        {/* Inner counter-rotating ring */}
        <motion.div
          style={{
            position: 'absolute',
            inset: -4,
            borderRadius: '50%',
            border: '1px dashed rgba(139,109,53,0.6)',
            borderDasharray: '3 12',
          }}
          animate={{ rotate: -360 }}
          transition={{ duration: 5, repeat: Infinity, ease: 'linear' }}
        />

        {/* Watch case */}
        <motion.div
          style={{
            position: 'absolute',
            inset: 0,
            borderRadius: '50%',
            background: '#1a1a1a',
            border: '2.5px solid #C8A45C',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            boxShadow: '0 0 30px rgba(200,164,92,0.15)',
          }}
          initial={{ scale: 0, opacity: 0 }}
          animate={{ scale: 1, opacity: 1 }}
          transition={{ duration: 0.6, ease: [0.34, 1.56, 0.64, 1], delay: 0.2 }}
        >
          {/* Inner bezel */}
          <div style={{
            position: 'absolute',
            inset: 6,
            borderRadius: '50%',
            border: '0.8px solid #8B6D35',
          }} />

          {/* Dial face */}
          <div style={{
            position: 'absolute',
            inset: 10,
            borderRadius: '50%',
            background: '#111111',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
          }}>

            {/* Hour tick marks */}
            {[0,30,60,90,120,150,180,210,240,270,300,330].map((deg, i) => (
              <motion.div
                key={deg}
                style={{
                  position: 'absolute',
                  width: [0,3,6,9].includes(i) ? 5 : 3,
                  height: [0,3,6,9].includes(i) ? 12 : 8,
                  background: [0,3,6,9].includes(i) ? '#C8A45C' : '#8B6D35',
                  borderRadius: 2,
                  top: '4px',
                  left: '50%',
                  marginLeft: [0,3,6,9].includes(i) ? -2.5 : -1.5,
                  transformOrigin: `50% ${[0,3,6,9].includes(i) ? 58 : 56}px`,
                  transform: `rotate(${deg}deg)`,
                }}
                initial={{ opacity: 0, scaleY: 0 }}
                animate={{ opacity: 1, scaleY: 1 }}
                transition={{ delay: 0.3 + i * 0.05, duration: 0.3 }}
              />
            ))}

            {/* Hour hand */}
            <motion.div
              style={{
                position: 'absolute',
                width: 5,
                height: 38,
                background: '#C8A45C',
                borderRadius: 2.5,
                bottom: '50%',
                left: '50%',
                marginLeft: -2.5,
                transformOrigin: 'bottom center',
              }}
              animate={{ rotate: 360 }}
              transition={{ duration: 4, repeat: Infinity, ease: 'linear' }}
            />

            {/* Minute hand */}
            <motion.div
              style={{
                position: 'absolute',
                width: 3,
                height: 50,
                background: '#E8C87A',
                borderRadius: 1.5,
                bottom: '50%',
                left: '50%',
                marginLeft: -1.5,
                transformOrigin: 'bottom center',
              }}
              animate={{ rotate: 360 }}
              transition={{ duration: 1.2, repeat: Infinity, ease: 'linear' }}
            />

            {/* Center cap */}
            <div style={{
              position: 'absolute',
              width: 10,
              height: 10,
              borderRadius: '50%',
              background: '#C8A45C',
              zIndex: 10,
            }} />
            <div style={{
              position: 'absolute',
              width: 5,
              height: 5,
              borderRadius: '50%',
              background: '#0f0f0f',
              zIndex: 11,
            }} />

            {/* Brand on dial */}
            <div style={{
              position: 'absolute',
              bottom: '28%',
              fontSize: 5,
              letterSpacing: '0.2em',
              color: 'rgba(200,164,92,0.8)',
              fontFamily: 'serif',
              userSelect: 'none',
            }}>
              WATCHIZER
            </div>

          </div>

          {/* Crown */}
          <div style={{
            position: 'absolute',
            right: -8,
            top: '50%',
            marginTop: -5,
            width: 8,
            height: 10,
            background: '#C8A45C',
            borderRadius: 2,
          }} />
        </motion.div>
      </div>

      {/* Brand name */}
      <motion.p
        style={{
          color: '#C8A45C',
          fontSize: '1.2rem',
          letterSpacing: '0.5em',
          fontFamily: "'Lato', 'Georgia', serif",
          fontWeight: 300,
          margin: '0 0 8px 0',
          userSelect: 'none',
        }}
        initial={{ opacity: 0, y: 10 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 0.8, duration: 0.6 }}
      >
        WATCHIZER
      </motion.p>

      {/* Tagline */}
      <motion.p
        style={{
          color: '#555',
          fontSize: '0.6rem',
          letterSpacing: '0.3em',
          fontFamily: 'sans-serif',
          margin: '0 0 28px 0',
          userSelect: 'none',
        }}
        initial={{ opacity: 0, y: 8 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ delay: 1.1, duration: 0.6 }}
      >
        LUXURY TIMEPIECES
      </motion.p>

      {/* Pulsing dots */}
      <motion.div
        style={{ display: 'flex', gap: 10 }}
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        transition={{ delay: 1.3 }}
      >
        {[0, 0.3, 0.6].map((delay, i) => (
          <motion.div
            key={i}
            style={{
              width: 6,
              height: 6,
              borderRadius: '50%',
              background: '#C8A45C',
            }}
            animate={{ opacity: [1, 0.3, 1] }}
            transition={{
              duration: 1.5,
              repeat: Infinity,
              delay,
              ease: 'easeInOut',
            }}
          />
        ))}
      </motion.div>

      {/* Bottom gold line */}
      <div style={{
        position: 'absolute',
        bottom: 0, left: 0, right: 0,
        height: '2px',
        background: 'linear-gradient(90deg, transparent, #C8A45C, transparent)',
      }} />

    </div>
  );
};

export default Loader;
