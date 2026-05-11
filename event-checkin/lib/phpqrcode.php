<?php
/**
 * Minimal QR Code Generator for PHP
 * 
 * Based on the phpqrcode library by Dominik Dzienia (LGPL 3.0).
 * This is a stripped-down version that generates QR codes using the 
 * Google Charts API as a fallback, or native GD library.
 * 
 * For production use, replace this with the full phpqrcode library
 * from https://github.com/t0k4rt/phpqrcode or install via composer.
 *
 * @license LGPL-3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Error correction levels.
define( 'QR_ECLEVEL_L', 0 );
define( 'QR_ECLEVEL_M', 1 );
define( 'QR_ECLEVEL_Q', 2 );
define( 'QR_ECLEVEL_H', 3 );

/**
 * Simple QR code generator class.
 * Uses Google Charts API for generation when GD is not available with full QR support.
 */
class QRcode {

    /**
     * Generate a QR code PNG file.
     *
     * @param string      $text     Text to encode.
     * @param string|bool $outfile  Output file path, or false for direct output.
     * @param int         $level    Error correction level.
     * @param int         $size     Module size in pixels.
     * @param int         $margin   Margin in modules.
     */
    public static function png( $text, $outfile = false, $level = QR_ECLEVEL_M, $size = 6, $margin = 2 ) {
        $ecl_map = array( 'L', 'M', 'Q', 'H' );
        $ecl     = isset( $ecl_map[ $level ] ) ? $ecl_map[ $level ] : 'M';
        
        $pixel_size = max( 200, $size * 40 );

        // Try using the bundled QR encoding if GD is available.
        if ( function_exists( 'imagecreate' ) ) {
            $image = self::generate_qr_image( $text, $size, $margin, $level );
            if ( $image ) {
                if ( $outfile ) {
                    imagepng( $image, $outfile );
                } else {
                    header( 'Content-Type: image/png' );
                    imagepng( $image );
                }
                imagedestroy( $image );
                return;
            }
        }

        // Fallback: use an external QR code API.
        $api_url = 'https://api.qrserver.com/v1/create-qr-code/?' . http_build_query( array(
            'data'   => $text,
            'size'   => $pixel_size . 'x' . $pixel_size,
            'ecc'    => $ecl,
            'margin' => $margin * $size,
            'format' => 'png',
        ) );

        $response = wp_remote_get( $api_url, array( 'timeout' => 15 ) );

        if ( is_wp_error( $response ) ) {
            // Generate a simple placeholder image.
            self::generate_placeholder( $text, $outfile, $pixel_size );
            return;
        }

        $body = wp_remote_retrieve_body( $response );

        if ( $outfile ) {
            file_put_contents( $outfile, $body );
        } else {
            header( 'Content-Type: image/png' );
            echo $body;
        }
    }

    /**
     * Generate QR code image using GD library with a basic QR encoding implementation.
     *
     * @param string $text   Text to encode.
     * @param int    $size   Module size.
     * @param int    $margin Margin modules.
     * @param int    $level  EC level.
     * @return resource|false GD image or false.
     */
    private static function generate_qr_image( $text, $size, $margin, $level ) {
        // Use a simple encoding approach for short URLs.
        $matrix = self::encode_to_matrix( $text );
        if ( ! $matrix ) {
            return false;
        }

        $matrix_size = count( $matrix );
        $img_size    = ( $matrix_size + 2 * $margin ) * $size;

        $image = imagecreate( $img_size, $img_size );
        $white = imagecolorallocate( $image, 255, 255, 255 );
        $black = imagecolorallocate( $image, 0, 0, 0 );

        imagefill( $image, 0, 0, $white );

        for ( $y = 0; $y < $matrix_size; $y++ ) {
            for ( $x = 0; $x < $matrix_size; $x++ ) {
                if ( ! empty( $matrix[ $y ][ $x ] ) ) {
                    imagefilledrectangle(
                        $image,
                        ( $x + $margin ) * $size,
                        ( $y + $margin ) * $size,
                        ( $x + $margin + 1 ) * $size - 1,
                        ( $y + $margin + 1 ) * $size - 1,
                        $black
                    );
                }
            }
        }

        return $image;
    }

    /**
     * Encode text into a QR matrix.
     * This is a simplified implementation for byte mode encoding.
     * For production, use the full phpqrcode library.
     *
     * @param string $text Text to encode.
     * @return array|false 2D array matrix.
     */
    private static function encode_to_matrix( $text ) {
        $len = strlen( $text );

        // Determine version based on data length (byte mode, EC level M).
        // Simplified capacity table for byte mode, EC level M.
        $capacities = array(
            1 => 14, 2 => 26, 3 => 42, 4 => 62, 5 => 84,
            6 => 106, 7 => 122, 8 => 152, 9 => 180, 10 => 213,
            11 => 251, 12 => 287, 13 => 331, 14 => 362, 15 => 412,
            16 => 450, 17 => 504, 18 => 560, 19 => 624, 20 => 666,
        );

        $version = 0;
        foreach ( $capacities as $v => $cap ) {
            if ( $len <= $cap ) {
                $version = $v;
                break;
            }
        }

        if ( $version === 0 ) {
            return false; // Text too long for our simple implementation.
        }

        $matrix_size = 17 + $version * 4;
        
        // Initialize matrix with null (unset).
        $matrix   = array_fill( 0, $matrix_size, array_fill( 0, $matrix_size, null ) );
        $reserved = array_fill( 0, $matrix_size, array_fill( 0, $matrix_size, false ) );

        // Place finder patterns.
        self::place_finder_pattern( $matrix, $reserved, 0, 0 );
        self::place_finder_pattern( $matrix, $reserved, $matrix_size - 7, 0 );
        self::place_finder_pattern( $matrix, $reserved, 0, $matrix_size - 7 );

        // Place separators.
        for ( $i = 0; $i < 8; $i++ ) {
            // Top-left.
            self::set_module( $matrix, $reserved, $i, 7, 0 );
            self::set_module( $matrix, $reserved, 7, $i, 0 );
            // Top-right.
            self::set_module( $matrix, $reserved, $i, $matrix_size - 8, 0 );
            self::set_module( $matrix, $reserved, 7, $matrix_size - 8 + $i, 0 );
            // Bottom-left.
            self::set_module( $matrix, $reserved, $matrix_size - 8, $i, 0 );
            self::set_module( $matrix, $reserved, $matrix_size - 8 + $i, 7, 0 );
        }

        // Place timing patterns.
        for ( $i = 8; $i < $matrix_size - 8; $i++ ) {
            $val = ( $i % 2 === 0 ) ? 1 : 0;
            self::set_module( $matrix, $reserved, 6, $i, $val );
            self::set_module( $matrix, $reserved, $i, 6, $val );
        }

        // Place alignment patterns for version >= 2.
        if ( $version >= 2 ) {
            $positions = self::get_alignment_positions( $version );
            foreach ( $positions as $row ) {
                foreach ( $positions as $col ) {
                    // Skip if overlapping with finder patterns.
                    if ( $reserved[ $row ][ $col ] ) {
                        continue;
                    }
                    self::place_alignment_pattern( $matrix, $reserved, $row, $col );
                }
            }
        }

        // Reserve format information areas.
        for ( $i = 0; $i < 9; $i++ ) {
            if ( $i < $matrix_size ) {
                $reserved[8][ $i ] = true;
                $reserved[ $i ][8] = true;
            }
        }
        for ( $i = $matrix_size - 8; $i < $matrix_size; $i++ ) {
            $reserved[8][ $i ] = true;
            $reserved[ $i ][8] = true;
        }

        // Dark module.
        self::set_module( $matrix, $reserved, $matrix_size - 8, 8, 1 );

        // Reserve version info for version >= 7.
        if ( $version >= 7 ) {
            for ( $i = 0; $i < 6; $i++ ) {
                for ( $j = $matrix_size - 11; $j < $matrix_size - 8; $j++ ) {
                    $reserved[ $i ][ $j ] = true;
                    $reserved[ $j ][ $i ] = true;
                }
            }
        }

        // Encode data.
        $data_bits = self::encode_data( $text, $version );

        // Place data bits in the matrix using the zigzag pattern.
        $bit_index = 0;
        $total_bits = count( $data_bits );

        for ( $right = $matrix_size - 1; $right >= 1; $right -= 2 ) {
            // Skip timing pattern column.
            if ( $right === 6 ) {
                $right = 5;
            }

            for ( $vert = 0; $vert < $matrix_size; $vert++ ) {
                for ( $j = 0; $j < 2; $j++ ) {
                    $col = $right - $j;
                    $upward = ( ( ( $matrix_size - 1 - $right ) / 2 ) % 2 === 0 );
                    // Fix: recalculate based on column pair.
                    $col_pair_index = (int) ( ( $matrix_size - 1 - $right + ( $right <= 6 ? 1 : 0 ) ) / 2 );
                    $upward = ( $col_pair_index % 2 === 0 );
                    $row = $upward ? ( $matrix_size - 1 - $vert ) : $vert;

                    if ( $row < 0 || $row >= $matrix_size || $col < 0 || $col >= $matrix_size ) {
                        continue;
                    }

                    if ( $reserved[ $row ][ $col ] ) {
                        continue;
                    }

                    if ( $bit_index < $total_bits ) {
                        $matrix[ $row ][ $col ] = $data_bits[ $bit_index ];
                        $bit_index++;
                    } else {
                        $matrix[ $row ][ $col ] = 0;
                    }
                }
            }
        }

        // Apply mask pattern 0 (checkerboard: (row + col) % 2 == 0).
        for ( $row = 0; $row < $matrix_size; $row++ ) {
            for ( $col = 0; $col < $matrix_size; $col++ ) {
                if ( ! $reserved[ $row ][ $col ] && $matrix[ $row ][ $col ] !== null ) {
                    if ( ( $row + $col ) % 2 === 0 ) {
                        $matrix[ $row ][ $col ] ^= 1;
                    }
                }
            }
        }

        // Place format information (mask 0, EC level M = 0b00 101 -> format bits).
        $format_bits = self::get_format_bits( 1, 0 ); // EC level M, mask 0
        self::place_format_info( $matrix, $format_bits, $matrix_size );

        // Place version info for version >= 7.
        if ( $version >= 7 ) {
            self::place_version_info( $matrix, $version, $matrix_size );
        }

        // Fill any remaining null values with 0.
        for ( $row = 0; $row < $matrix_size; $row++ ) {
            for ( $col = 0; $col < $matrix_size; $col++ ) {
                if ( $matrix[ $row ][ $col ] === null ) {
                    $matrix[ $row ][ $col ] = 0;
                }
            }
        }

        return $matrix;
    }

    /**
     * Place a finder pattern at the given position.
     */
    private static function place_finder_pattern( &$matrix, &$reserved, $row, $col ) {
        $pattern = array(
            array(1,1,1,1,1,1,1),
            array(1,0,0,0,0,0,1),
            array(1,0,1,1,1,0,1),
            array(1,0,1,1,1,0,1),
            array(1,0,1,1,1,0,1),
            array(1,0,0,0,0,0,1),
            array(1,1,1,1,1,1,1),
        );

        for ( $r = 0; $r < 7; $r++ ) {
            for ( $c = 0; $c < 7; $c++ ) {
                $mr = $row + $r;
                $mc = $col + $c;
                if ( $mr >= 0 && $mr < count( $matrix ) && $mc >= 0 && $mc < count( $matrix ) ) {
                    $matrix[ $mr ][ $mc ]   = $pattern[ $r ][ $c ];
                    $reserved[ $mr ][ $mc ] = true;
                }
            }
        }
    }

    /**
     * Place an alignment pattern centered at the given position.
     */
    private static function place_alignment_pattern( &$matrix, &$reserved, $center_row, $center_col ) {
        $pattern = array(
            array(1,1,1,1,1),
            array(1,0,0,0,1),
            array(1,0,1,0,1),
            array(1,0,0,0,1),
            array(1,1,1,1,1),
        );

        for ( $r = -2; $r <= 2; $r++ ) {
            for ( $c = -2; $c <= 2; $c++ ) {
                $mr = $center_row + $r;
                $mc = $center_col + $c;
                if ( $mr >= 0 && $mr < count( $matrix ) && $mc >= 0 && $mc < count( $matrix ) ) {
                    $matrix[ $mr ][ $mc ]   = $pattern[ $r + 2 ][ $c + 2 ];
                    $reserved[ $mr ][ $mc ] = true;
                }
            }
        }
    }

    /**
     * Get alignment pattern positions for a given version.
     */
    private static function get_alignment_positions( $version ) {
        $table = array(
            2  => array(6, 18),
            3  => array(6, 22),
            4  => array(6, 26),
            5  => array(6, 30),
            6  => array(6, 34),
            7  => array(6, 22, 38),
            8  => array(6, 24, 42),
            9  => array(6, 26, 46),
            10 => array(6, 28, 50),
            11 => array(6, 30, 54),
            12 => array(6, 32, 58),
            13 => array(6, 34, 62),
            14 => array(6, 26, 46, 66),
            15 => array(6, 26, 48, 70),
            16 => array(6, 26, 50, 74),
            17 => array(6, 30, 54, 78),
            18 => array(6, 30, 56, 82),
            19 => array(6, 30, 58, 86),
            20 => array(6, 34, 62, 90),
        );

        return isset( $table[ $version ] ) ? $table[ $version ] : array();
    }

    /**
     * Set a module in the matrix and mark as reserved.
     */
    private static function set_module( &$matrix, &$reserved, $row, $col, $value ) {
        if ( $row >= 0 && $row < count( $matrix ) && $col >= 0 && $col < count( $matrix[0] ) ) {
            $matrix[ $row ][ $col ]   = $value;
            $reserved[ $row ][ $col ] = true;
        }
    }

    /**
     * Encode data into bits for the QR code (byte mode).
     */
    private static function encode_data( $text, $version ) {
        $bits = array();

        // Mode indicator for byte mode: 0100.
        $bits = array_merge( $bits, array( 0, 1, 0, 0 ) );

        // Character count (8 bits for versions 1-9, 16 bits for 10+).
        $len = strlen( $text );
        $count_bits = ( $version <= 9 ) ? 8 : 16;
        for ( $i = $count_bits - 1; $i >= 0; $i-- ) {
            $bits[] = ( $len >> $i ) & 1;
        }

        // Data bytes.
        for ( $i = 0; $i < $len; $i++ ) {
            $byte = ord( $text[ $i ] );
            for ( $b = 7; $b >= 0; $b-- ) {
                $bits[] = ( $byte >> $b ) & 1;
            }
        }

        // Terminator (up to 4 bits of zeros).
        $total_codewords = self::get_total_codewords( $version );
        $data_codewords  = self::get_data_codewords( $version, 1 ); // EC level M.
        $total_data_bits = $data_codewords * 8;

        $terminator_len = min( 4, $total_data_bits - count( $bits ) );
        for ( $i = 0; $i < $terminator_len; $i++ ) {
            $bits[] = 0;
        }

        // Pad to byte boundary.
        while ( count( $bits ) % 8 !== 0 ) {
            $bits[] = 0;
        }

        // Pad codewords.
        $pad_bytes = array( 0xEC, 0x11 );
        $pad_index = 0;
        while ( count( $bits ) < $total_data_bits ) {
            $byte = $pad_bytes[ $pad_index % 2 ];
            for ( $b = 7; $b >= 0; $b-- ) {
                $bits[] = ( $byte >> $b ) & 1;
            }
            $pad_index++;
        }

        // Generate error correction codewords.
        $data_bytes = array();
        for ( $i = 0; $i < count( $bits ); $i += 8 ) {
            $byte = 0;
            for ( $b = 0; $b < 8 && ( $i + $b ) < count( $bits ); $b++ ) {
                $byte = ( $byte << 1 ) | $bits[ $i + $b ];
            }
            $data_bytes[] = $byte;
        }

        $ec_codewords_count = $total_codewords - $data_codewords;
        $ec_bytes = self::generate_ec_codewords( $data_bytes, $ec_codewords_count );

        // Append EC codewords.
        foreach ( $ec_bytes as $byte ) {
            for ( $b = 7; $b >= 0; $b-- ) {
                $bits[] = ( $byte >> $b ) & 1;
            }
        }

        return $bits;
    }

    /**
     * Get total codewords for a version.
     */
    private static function get_total_codewords( $version ) {
        $table = array(
            1 => 26, 2 => 44, 3 => 70, 4 => 100, 5 => 134,
            6 => 172, 7 => 196, 8 => 242, 9 => 292, 10 => 346,
            11 => 404, 12 => 466, 13 => 532, 14 => 581, 15 => 655,
            16 => 733, 17 => 815, 18 => 901, 19 => 991, 20 => 1085,
        );
        return isset( $table[ $version ] ) ? $table[ $version ] : 26;
    }

    /**
     * Get data codewords for a version and EC level.
     */
    private static function get_data_codewords( $version, $ec_level ) {
        // EC level M data codewords.
        $table = array(
            1 => 16, 2 => 28, 3 => 44, 4 => 64, 5 => 86,
            6 => 108, 7 => 124, 8 => 154, 9 => 182, 10 => 216,
            11 => 254, 12 => 290, 13 => 334, 14 => 365, 15 => 415,
            16 => 453, 17 => 507, 18 => 563, 19 => 627, 20 => 669,
        );
        return isset( $table[ $version ] ) ? $table[ $version ] : 16;
    }

    /**
     * Generate error correction codewords using Reed-Solomon.
     */
    private static function generate_ec_codewords( $data, $ec_count ) {
        // Generator polynomial coefficients (log form) for common EC counts.
        $gen_poly = self::get_generator_polynomial( $ec_count );

        // Create message polynomial.
        $msg = array_values( $data );

        // Pad message with zeros for EC.
        for ( $i = 0; $i < $ec_count; $i++ ) {
            $msg[] = 0;
        }

        // Polynomial division.
        for ( $i = 0; $i < count( $data ); $i++ ) {
            $coef = $msg[ $i ];
            if ( $coef !== 0 ) {
                $log_coef = self::gf_log( $coef );
                for ( $j = 0; $j < count( $gen_poly ); $j++ ) {
                    $msg[ $i + $j ] ^= self::gf_exp( ( $gen_poly[ $j ] + $log_coef ) % 255 );
                }
            }
        }

        // EC codewords are the remainder.
        return array_slice( $msg, count( $data ) );
    }

    /**
     * Get generator polynomial for a given number of EC codewords.
     * Returns coefficients in log form.
     */
    private static function get_generator_polynomial( $degree ) {
        // Start with (x - a^0).
        $gen = array( 0 );

        for ( $i = 1; $i < $degree; $i++ ) {
            // Multiply by (x - a^i).
            $new_gen = array_fill( 0, count( $gen ) + 1, 0 );
            for ( $j = 0; $j < count( $gen ); $j++ ) {
                // x term.
                $new_gen[ $j ] ^= self::gf_exp( $gen[ $j ] );
                // constant term.
                $a_i = self::gf_exp( ( $gen[ $j ] + $i ) % 255 );
                $new_gen[ $j + 1 ] ^= $a_i;
            }
            // Convert back to log form.
            for ( $j = 0; $j < count( $new_gen ); $j++ ) {
                $new_gen[ $j ] = self::gf_log( $new_gen[ $j ] );
            }
            $gen = $new_gen;
        }

        return $gen;
    }

    /**
     * GF(256) exponentiation table.
     */
    private static function gf_exp( $exp ) {
        static $table = null;
        if ( $table === null ) {
            $table = array();
            $val   = 1;
            for ( $i = 0; $i < 256; $i++ ) {
                $table[ $i ] = $val;
                $val <<= 1;
                if ( $val >= 256 ) {
                    $val ^= 0x11D; // QR code primitive polynomial.
                }
            }
        }
        return $table[ $exp % 255 ];
    }

    /**
     * GF(256) logarithm table.
     */
    private static function gf_log( $val ) {
        static $table = null;
        if ( $table === null ) {
            $table = array();
            $v     = 1;
            for ( $i = 0; $i < 255; $i++ ) {
                $table[ $v ] = $i;
                $v <<= 1;
                if ( $v >= 256 ) {
                    $v ^= 0x11D;
                }
            }
        }
        return isset( $table[ $val ] ) ? $table[ $val ] : 0;
    }

    /**
     * Get format information bits.
     */
    private static function get_format_bits( $ec_level, $mask ) {
        // Format info lookup table (EC level M, mask 0-7).
        $format_table = array(
            // EC Level L.
            0 => array(
                0 => 0x77C4, 1 => 0x72F3, 2 => 0x7DAA, 3 => 0x789D,
                4 => 0x662F, 5 => 0x6318, 6 => 0x6C41, 7 => 0x6976,
            ),
            // EC Level M.
            1 => array(
                0 => 0x5412, 1 => 0x5125, 2 => 0x5E7C, 3 => 0x5B4B,
                4 => 0x45F9, 5 => 0x40CE, 6 => 0x4F97, 7 => 0x4AA0,
            ),
            // EC Level Q.
            2 => array(
                0 => 0x355F, 1 => 0x3068, 2 => 0x3F31, 3 => 0x3A06,
                4 => 0x24B4, 5 => 0x2183, 6 => 0x2EDA, 7 => 0x2BED,
            ),
            // EC Level H.
            3 => array(
                0 => 0x1689, 1 => 0x13BE, 2 => 0x1CE7, 3 => 0x19D0,
                4 => 0x0762, 5 => 0x0255, 6 => 0x0D0C, 7 => 0x083B,
            ),
        );

        $bits_val = isset( $format_table[ $ec_level ][ $mask ] )
            ? $format_table[ $ec_level ][ $mask ]
            : 0x5412;

        $bits = array();
        for ( $i = 14; $i >= 0; $i-- ) {
            $bits[] = ( $bits_val >> $i ) & 1;
        }

        return $bits;
    }

    /**
     * Place format information in the matrix.
     */
    private static function place_format_info( &$matrix, $format_bits, $size ) {
        // Around top-left finder pattern.
        $positions_a = array(
            array(0, 8), array(1, 8), array(2, 8), array(3, 8),
            array(4, 8), array(5, 8), array(7, 8), array(8, 8),
            array(8, 7), array(8, 5), array(8, 4), array(8, 3),
            array(8, 2), array(8, 1), array(8, 0),
        );

        // Around other finder patterns.
        $positions_b = array(
            array(8, $size - 1), array(8, $size - 2), array(8, $size - 3),
            array(8, $size - 4), array(8, $size - 5), array(8, $size - 6),
            array(8, $size - 7), array($size - 8, 8), array($size - 7, 8),
            array($size - 6, 8), array($size - 5, 8), array($size - 4, 8),
            array($size - 3, 8), array($size - 2, 8), array($size - 1, 8),
        );

        for ( $i = 0; $i < 15; $i++ ) {
            $bit = $format_bits[ $i ];

            if ( isset( $positions_a[ $i ] ) ) {
                $matrix[ $positions_a[ $i ][0] ][ $positions_a[ $i ][1] ] = $bit;
            }
            if ( isset( $positions_b[ $i ] ) ) {
                $matrix[ $positions_b[ $i ][0] ][ $positions_b[ $i ][1] ] = $bit;
            }
        }
    }

    /**
     * Place version information for version >= 7.
     */
    private static function place_version_info( &$matrix, $version, $size ) {
        if ( $version < 7 ) return;

        $version_table = array(
            7  => 0x07C94, 8  => 0x085BC, 9  => 0x09A99, 10 => 0x0A4D3,
            11 => 0x0BBF6, 12 => 0x0C762, 13 => 0x0D847, 14 => 0x0E60D,
            15 => 0x0F928, 16 => 0x10B78, 17 => 0x1145D, 18 => 0x12A17,
            19 => 0x13532, 20 => 0x149A6,
        );

        $bits_val = isset( $version_table[ $version ] ) ? $version_table[ $version ] : 0;

        for ( $i = 0; $i < 18; $i++ ) {
            $bit = ( $bits_val >> $i ) & 1;
            $row = (int) ( $i / 3 );
            $col = $i % 3;

            $matrix[ $row ][ $size - 11 + $col ] = $bit;
            $matrix[ $size - 11 + $col ][ $row ] = $bit;
        }
    }

    /**
     * Generate a placeholder image when QR generation fails.
     */
    private static function generate_placeholder( $text, $outfile, $size ) {
        if ( ! function_exists( 'imagecreate' ) ) {
            return;
        }

        $image = imagecreate( $size, $size );
        $white = imagecolorallocate( $image, 255, 255, 255 );
        $gray  = imagecolorallocate( $image, 128, 128, 128 );

        imagefill( $image, 0, 0, $white );
        imagestring( $image, 3, 10, $size / 2 - 5, 'QR Code', $gray );

        if ( $outfile ) {
            imagepng( $image, $outfile );
        } else {
            header( 'Content-Type: image/png' );
            imagepng( $image );
        }

        imagedestroy( $image );
    }
}
