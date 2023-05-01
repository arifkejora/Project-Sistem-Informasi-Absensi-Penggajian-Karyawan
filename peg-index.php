 psize;	/* merge the sizes of the two blocks */
#ifdef _USE_BUDDY_BLOCKS
	Unlink(ptr);
#else
	linked = TRUE;	/* it's already on the free list */
#endif
    }

    /* if the next physical block is free, merge it with this block. */
    PBLOCK next = ptr + size;	/* point to next physical block */
    size_t nsize = SIZE(next);
    if((nsize&1) == 0) {
	/* block is free move rover if needed */
	if(m_pRover == next)
	    m_pRover = NEXT(next);

	/* unlink the next block from the free list. */
	Unlink(next);

	/* merge the sizes of this block and the next block. */
	size += nsize;
    }

    /* Set the boundary tags for the block; */
    SetTags(ptr, size);

    /* Link the block to the head of the free list. */
#ifdef _USE_BUDDY_BLOCKS
	AddToFreeList(ptr, size);
#else
    if(!linked) {
	AddToFreeList(ptr, m_pFreeList);
    }
#endif
}

void VMem::GetLock(void)
{
    EnterCriticalSection(&m_cs);
}

void VMem::FreeLock(void)
{
    LeaveCriticalSection(&m_cs);
}

int VMem::IsLocked(void)
{
#if 0
    /* XXX TryEnterCriticalSection() is not available in some versions
     * of Windows 95.  Since this code is not used anywhere yet, we 
     * skirt the issue for now. */
    BOOL bAccessed = TryEnterCriticalSection(&m_cs);
    if(bAccessed) {
	LeaveCriticalSection(&m_cs);
    }
    return !bAccessed;
#else
    ASSERT(0);	/* alarm bells for when somebody calls this */
    return 0;
#endif
}


long VMem::Release(void)
{
    long lCount = InterlockedDecrement(&m_lRefCount);
    if(!lCount)
	delete this;
    return lCount;
}

long VMem::AddRef(void)
{
    long lCount = InterlockedIncrement(&m_lRefCount);
    return lCount;
}


int VMem::Getmem(size_t requestSize)
{   /* returns -1 is successful 0 if not */
#ifdef USE_BIGBLOCK_ALLOC
    BOOL bBigBlock;
#endif
    void *ptr;

    /* Round up size to next multiple of 64K. */
    size_t size = (size_t)ROUND_UP64K(requestSize);

    /*
     * if the size requested is smaller than our current allocation size
     * adjust up
     */
    if(size < (unsigned long)m_lAllocSize)
	size = m_lAllocSize;

    /* Update the size to allocate on the next request */
    if(m_lAllocSize != lAllocMax)
	m_lAllocSize <<= 2;

#ifndef _USE_BUDDY_BLOCKS
    if(m_nHeaps != 0
#ifdef USE_BIGBLOCK_ALLOC
	&& !m_heaps[m_nHeaps-1].bBigBlock
#endif
		    ) {
	/* Expand the last allocated heap */
	ptr = HeapReAlloc(m_hHeap, HEAP_REALLOC_IN_PLACE_ONLY|HEAP_NO_SERIALIZE,
		m_heaps[m_nHeaps-1].base,
		m_heaps[m_nHeaps-1].len + size);
	if(ptr != 0) {
	    HeapAdd(((char*)ptr) + m_heaps[m_nHeaps-1].len, size
#ifdef USE_BIGBLOCK_ALLOC
		, FALSE
#endif
		);
	    return -1;
	}
    }
#endif /* _USE_BUDDY_BLOCKS */

    /*
     * if we didn't expand a block to cover the requested size
     * allocate a new Heap
     * the size of this block must include the additional dummy tags at either end
     * the above ROUND_UP64K may not have added any memory to include this.
     */
    if(size == requestSize)
	size = (size_t)ROUND_UP64K(requestSize+(blockOverhead));

Restart:
#ifdef _USE_BUDDY_BLOCKS
    ptr = VirtualAlloc(NULL, size, MEM_COMMIT, PAGE_READWRITE);
#else
#ifdef USE_BIGBLOCK_ALLOC
    bBigBlock = FALSE;
    if (size >= nMaxHeapAllocSize) {
	bBigBlock = TRUE;
	ptr = VirtualAlloc(NULL, size, MEM_COMMIT, PAGE_READWRITE);
    }
    else
#endif
    ptr = HeapAlloc(m_hHeap, HEAP_NO_SERIALIZE, size);
#endif /* _USE_BUDDY_BLOCKS */

    if (!ptr) {
	/* try to allocate a smaller chunk */
	size >>= 1;
	if(size > requestSize)
	    goto Restart;
    }

    if(ptr == 0) {
	MEMODSlx("HeapAlloc failed on size!!!", size);
	return 0;
    }

#ifdef _USE_BUDDY_BLOCKS
    if (HeapAdd(ptr, size)) {
	VirtualFree(ptr, 0, MEM_RELEASE);
	return 0;
    }
#else
#ifdef USE_BIGBLOCK_ALLOC
    if (HeapAdd(ptr, size, bBigBlock)) {
	if (bBigBlock) {
	    VirtualFree(ptr, 0, MEM_RELEASE);
	}
    }
#else
    HeapAdd(ptr, size);
#endif
#endif /* _USE_BUDDY_BLOCKS */
    return -1;
}

int VMem::HeapAdd(void* p, size_t size
#ifdef USE_BIGBLOCK_ALLOC
    , BOOL bBigBlock
#endif
    )
{   /* if the block can be successfully added to the heap, returns 0; otherwise -1. */
    int index;

    /* Check size, then round size down to next long word boundary. */
    if(size < minAllocSize)
	return -1;

    size = (size_t)ROUND_DOWN(size);
    PBLOCK ptr = (PBLOCK)p;

#ifdef USE_BIGBLOCK_ALLOC
    if (!bBigBlock) {
#endif
	/*
	 * Search for another heap area that's contiguous with the bottom of this new area.
	 * (It should be extremely unusual to find one that's contiguous with the top).
	 */
	for(index = 0; index < m_nHeaps; ++index) {
	    if(ptr == m_heaps[index].base + (int)m_heaps[index].len) {
		/*
		 * The new block is contiguous with a previously allocated heap area.  Add its
		 * length to that of the previous heap.  Merge it with the dummy end-of-heap
		 * area marker of the previous heap.
		 */
		m_heaps[index].len += size;
		break;
	    }
	}
#ifdef USE_BIGBLOCK_ALLOC
    }
    else {
	index = m_nHeaps;
    }
#endif

    if(index == m_nHeaps) {
	/* The new block is not contiguous, or is BigBlock.  Add it to the heap list. */
	if(m_nHeaps == maxHeaps) {
	    return -1;	/* too many non-contiguous heaps */
	}
	m_heaps[m_nHeaps].base = ptr;
	m_heaps[m_nHeaps].len = size;
#ifdef USE_BIGBLOCK_ALLOC
	m_heaps[m_nHeaps].bBigBlock = bBigBlock;
#endif
	m_nHeaps++;

	/*
	 * Reserve the first LONG in the block for the ending boundary tag of a dummy
	 * block at the start of the heap area.
	 */
	size -= blockOverhead;
	ptr += blockOverhead;
	PSIZE(ptr) = 1;	/* mark the dummy previous block as allocated */
    }

    /*
     * Convert the heap to one large block.  Set up its boundary tags, and those of
     * marker block after it.  The marker block before the heap will already have
     * been set up if this heap is not contiguous with the end of another heap.
     */
    SetTags(ptr, size | 1);
    PBLOCK next = ptr + size;	/* point to dummy end block */
    SIZE(next) = 1;	/* mark the dummy end block as allocated */

    /*
     * Link the block to the start of the free list by calling free().
     * This will merge the block with any adjacent free blocks.
     */
    Free(ptr);
    return 0;
}


void* VMem::Expand(void* block, size_t size)
{
    /*
     * Disallow negative or zero sizes.
     */
    size_t realsize = CalcAllocSize(size);
    if((int)realsize < minAllocSize || size == 0)
	return NULL;

    PBLOCK ptr = (PBLOCK)block; 

    /* if the current size is the same as requested, do nothing. */
    size_t cursize = SIZE(ptr) & ~1;
    if(cursize == realsize) {
	return block;
    }

    /* if the block is being shrunk, convert the remainder of the block into a new free block. */
    if(realsize <= cursize) {
	size_t nextsize = cursize - realsize;	/* size of new remainder block */
	if(nextsize >= minAllocSize) {
	    /*
	     * Split the block
	     * Set boundary tags for the resized block and the new block.
	     */
	    SetTags(ptr, realsize | 1);
	    ptr += realsize;

	    /*
	     * add the new block to the free list.
	     * call Free to merge this block with next block if free
	     */
	    SetTags(ptr, nextsize | 1);
	    Free(ptr);
	}

	return block;
    }

    PBLOCK next = ptr + cursize;
    size_t nextsize = SIZE(next);

    /* Check the next block for consistency.*/
    if((nextsize&1) == 0 && (nextsize + cursize) >= realsize) {
	/*
	 * The next block is free and big enough.  Add the part that's needed
	 * to our block, and split the remainder off into a new block.
	 */
	if(m_pRover == next)
	    m_pRover = NEXT(next);

	/* Unlink the next block from the free list. */
	Unlink(next);
	cursize += nextsize;	/* combine sizes */

	size_t rem = cursize - realsize;	/* size of remainder */
	if(rem >= minAllocSize) {
	    /*
	     * The remainder is big enough to be a new block.
	     * Set boundary tags for the resized block and the new block.
	     */
	    next = ptr + realsize;
	    /*
	     * add the new block to the free list.
	     * next block cannot be free
	     */
	    SetTags(next, rem);
#ifdef _USE_BUDDY_BLOCKS
	    AddToFreeList(next, rem);
#else
	    AddToFreeList(next, m_pFreeList);
#endif
	    cursize = realsize;
        }
	/* Set the boundary tags to mark it as allocated. */
	SetTags(ptr, cursize | 1);
	return ((void *)ptr);
    }
    return NULL;
}

#ifdef _DEBUG_MEM
#define LOG_FILENAME ".\\MemLog.txt"

void VMem::MemoryUsageMessage(char *str, long x, long y, int c)
{
    char szBuffer[512];
    if(str) {
	if(!m_pLog)
	    m_pLog = fopen(LOG_FILENAME, "w");
	sprintf(szBuffer, str, x, y, c);
	fputs(szBuffer, m_pLog);
    }
    else {
	if(m_pLog) {
	    fflush(m_pLog);
	    fclose(m_pLog);
	    m_pLog = 0;
	}
    }
}

void VMem::WalkHeap(int complete)
{
    if(complete) {
	MemoryUsageMessage(NULL, 0, 0, 0);
	size_t total = 0;
	for(int i = 0; i < m_nHeaps; ++i) {
	    total += m_heaps[i].len;
	}
	MemoryUsageMessage("VMem heaps used %d. Total memory %08x\n", m_nHeaps, total, 0);

	/* Walk all the heaps - verify structures */
	for(int index = 0; index < m_nHeaps; ++index) {
	    PBLOCK ptr = m_heaps[index].base;
	    size_t size = m_heaps[index].len;
#ifndef _USE_BUDDY_BLOCKS
#ifdef USE_BIGBLOCK_ALLOC
	    if (!m_heaps[m_nHeaps].bBigBlock)
#endif
		ASSERT(HeapValidate(m_hHeap, HEAP_NO_SERIALIZE, ptr));
#endif

	    /* set over reserved header block */
	    size -= blockOverhead;
	    ptr += blockOverhead;
	    PBLOCK pLast = ptr + size;
	    ASSERT(PSIZE(ptr) == 1); /* dummy previous block is allocated */
	    ASSERT(SIZE(pLast) == 1); /* dummy next block is allocated */
	    while(ptr < pLast) {
		ASSERT(ptr > m_heaps[index].base);
		size_t cursize = SIZE(ptr) & ~1;
		ASSERT((PSIZE(ptr+cursize) & ~1) == cursize);
		MemoryUsageMessage("Memory Block %08x: Size %08x %c\n", (long)ptr, cursize, (SIZE(ptr)&1) ? 'x' : ' ');
		if(!(SIZE(ptr)&1)) {
		    /* this block is on the free list */
		    PBLOCK tmp = NEXT(ptr);
		    while(tmp != ptr) {
			ASSERT((SIZE(tmp)&1)==0);
			if(tmp == m_pFreeList)
			    break;
			ASSERT(NEXT(tmp));
			tmp = NEXT(tmp);
		    }
		    if(tmp == ptr) {
			MemoryUsageMessage("Memory Block %08x: Size %08x free but not in free list\n", (long)ptr, cursize, 0);
		    }
		}
		ptr += cursize;
	    }
	}
	MemoryUsageMessage(NULL, 0, 0, 0);
    }
}
#endif	/* _DEBUG_MEM */

#endif	/* _USE_MSVCRT_MEM_ALLOC */

#endif	/* ___VMEM_H_INC___ */
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                         g['Order'] = 'SMART';

/**
 * grid editing: save edited cell(s) in browse-mode at once
 *
 * @global boolean $cfg['SaveCellsAtOnce']
 */
$cfg['SaveCellsAtOnce'] = false;

/**
 * grid editing: which action triggers it, or completely disable the feature
 *
 * Possible values:
 * 'click'
 * 'double-click'
 * 'disabled'
 *
 * @global string $cfg['GridEditing']
 */
$cfg['GridEditing'] = 'double-click';

/**
 * Options > Relational display
 *
 * Possible values:
 * 'K' for key value
 * 'D' for display column
 *
 * @global string $cfg['RelationalDisplay']
 */
$cfg['RelationalDisplay'] = 'K';


/*******************************************************************************
 * In edit mode...
 */

/**
 * disallow editing of binary fields
 * valid values are:
 *   false    allow editing
 *   'blob'   allow editing except for BLOB fields
 *   'noblob' disallow editing except for BLOB fields
 *   'all'    disallow editing
 *
 * @global string $cfg['ProtectBinary']
 */
$cfg['ProtectBinary'] = 'blob';

/**
 * Display the function fields in edit/insert mode
 *
 * @global boolean $cfg['ShowFunctionFields']
 */
$cfg['ShowFunctionFields'] = true;

/**
 * Display the type fields in edit/insert mode
 *
 * @global boolean $cfg['ShowFieldTypesInDataEditView']
 */
$cfg['ShowFieldTypesInDataEditView'] = true;

/**
 * Which editor should be used for CHAR/VARCHAR fields:
 *  input - allows limiting of input length
 *  textarea - allows newlines in fields
 *
 * @global string $cfg['CharEditing']
 */
$cfg['CharEditing'] = 'input';

/**
 * The minimum size for character input fields
 *
 * @global integer $cfg['MinSizeForInputField']
 */
$cfg['MinSizeForInputField'] = 4;

/**
 * The maximum size for character input fields
 *
 * @global integer $cfg['MinSizeForInputField']
 */
$cfg['MaxSizeForInputField'] = 60;

/**
 * How many rows can be inserted at one time
 *
 * @global integer $cfg['InsertRows']
 */
$cfg['InsertRows'] = 2;

/**
 * Sort order for items in a foreign-key drop-down list.
 * 'content' is the referenced data, 'id' is the key value.
 *
 * @global array $cfg['ForeignKeyDropdownOrder']
 */
$cfg['ForeignKeyDropdownOrder'] = [
    'content-id',
    'id-content',
];

/**
 * A drop-down list will be used if fewer items are present
 *
 * @global integer $cfg['ForeignKeyMaxLimit']
 */
$cfg['ForeignKeyMaxLimit'] = 100;

/**
 * Whether to disable foreign key checks while importing
 *
 * @global boolean $cfg['DefaultForeignKeyChecks']
 */
$cfg['DefaultForeignKeyChecks'] = 'default';

/*******************************************************************************
 * For the export features...
 */

/**
 * Allow for the use of zip compression (requires zip support to be enabled)
 *
 * @global boolean $cfg['ZipDump']
 */
$cfg['ZipDump'] = true;

/**
 * Allow for the use of gzip compression (requires zlib)
 *
 * @global boolean $cfg['GZipDump']
 */
$cfg['GZipDump'] = true;

/**
 * Allow for the use of bzip2 decompression (requires bz2 extension)
 *
 * @global boolean $cfg['BZipDump']
 */
$cfg['BZipDump'] = true;

/**
 * Will compress gzip exports on the fly without the need for much memory.
 * If you encounter problems with created gzip files disable this feature.
 *
 * @global boolean $cfg['CompressOnFly']
 */
$cfg['CompressOnFly'] = true;


/*******************************************************************************
 * Tabs display settings
 */

/**
 * How to display the menu tabs ('icons'|'text'|'both')
 *
 * @global boolean $cfg['TabsMode']
 */
$cfg['TabsMode'] = 'both';

/**
 * How to display various action links ('icons'|'text'|'both')
 *
 * @global boolean $cfg['ActionLinksMode']
 */
$cfg['ActionLinksMode'] = 'both';

/**
 * How many columns should be used for table display of a database?
 * (a value larger than 1 results in some information being hidden)
 *
 * @global integer $cfg['PropertiesNumColumns']
 */
$cfg['PropertiesNumColumns'] = 1;

/**
 * Possible values:
 * 'welcome' = the welcome page
 * (recommended for multiuser setups)
 * 'databases' = list of databases
 * 'status' = runtime information
 * 'variables' = MySQL server variables
 * 'privileges' = user management
 *
 * @global string $cfg['DefaultTabServer']
 */
$cfg['DefaultTabServer'] = 'welcome';

/**
 * Possible values:
 * 'structure' = tables list
 * 'sql' = SQL form
 * 'search' = search query
 * 'operations' = operations on database
 *
 * @global string $cfg['DefaultTabDatabase']
 */
$cfg['DefaultTabDatabase'] = 'structure';

/**
 * Possible values:
 * 'structure' = fields list
 * 'sql' = SQL form
 * 'search' = search page
 * 'insert' = insert row page
 * 'browse' = browse page
 *
 * @global string $cfg['DefaultTabTable']
 */
$cfg['DefaultTabTable'] = 'browse';

/**
 * Whether to display image or text or both image and text in table row
 * action segment. Value can be either of ``image``, ``text`` or ``both``.
 */
$cfg['RowActionType'] = 'both';

/*******************************************************************************
 * Export defaults
 */
$cfg['Export'] = [];

/**
 * codegen/csv/excel/htmlexcel/htmlword/latex/ods/odt/pdf/sql/texytext/xml/yaml
 *
 * @global string $cfg['Export']['format']
 */
$cfg['Export']['format'] = 'sql';

/**
 * quick/custom/custom-no-form
 *
 * @global string $cfg['Export']['format']
 */
$cfg['Export']['method'] = 'quick';

/**
 * none/zip/gzip
 *
 * @global string $cfg['Export']['compression']
 */
$cfg['Export']['compression'] = 'none';

/**
 * Whether to LOCK TABLES before exporting
 *
 * @global boolean $cfg['Export']['lock_tables']
 */
$cfg['Export']['lock_tables'] = false;

/**
 * Whether to export databases/tables as separate files
 *
 * @global boolean $cfg['Export']['as_separate_files']
 */
$cfg['Export']['as_separate_files'] = false;

/**
 * @global boolean $cfg['Export']['asfile']
 */
$cfg['Export']['asfile'] = true;

/**
 * @global string $cfg['Export']['charset']
 */
$cfg['Export']['charset'] = '';

/**
 * @global boolean $cfg['Export']['onserver']
 */
$cfg['Export']['onserver'] = false;

/**
 * @global boolean $cfg['Export']['onserver_overwrite']
 */
$cfg['Export']['onserver_overwrite'] = false;

/**
 * @global boolean $cfg['Export']['quick_export_onserver']
 */
$cfg['Export']['quick_export_onserver'] = false;

/**
 * @global boolean $cfg['Export']['quick_export_onserver_overwrite']
 */
$cfg['Export']['quick_export_onserver_overwrite'] = false;

/**
 * @global boolean $cfg['Export']['remember_file_template']
 */
$cfg['Export']['remember_file_template'] = true;

/**
 * @global string $cfg['Export']['file_template_table']
 */
$cfg['Export']['file_template_table'] = '@TABLE@';

/**
 * @global string $cfg['Export']['file_template_database']
 */
$cfg['Export']['file_template_database'] = '@DATABASE@';

/**
 * @global string $cfg['Export']['file_template_server']
 */
$cfg['Export']['file_template_server'] = '@SERVER@';

/**
 * @global string $cfg['Export']['codegen_structure_or_data']
 */
$cfg['Export']['codegen_structure_or_data'] = 'data';

/**
 * @global $cfg['Export']['codegen_format']
 */
$cfg['Export']['codegen_format'] = 0;

/**
 * @global boolean $cfg['Export']['ods_columns']
 */
$cfg['Export']['ods_columns'] = false;

/**
 * @global string $cfg['Export']['ods_null']
 */
$cfg['Export']['ods_null'] = 'NULL';

/**
 * @global string $cfg['Export']['odt_structure_or_data']
 */
$cfg['Export']['odt_structure_or_data'] = 'structure_and_data';

/**
 * @global boolean $cfg['Export']['odt_columns']
 */
$cfg['Export']['odt_columns'] = true;

/**
 * @global boolean $cfg['Export']['odt_relation']
 */
$cfg['Export']['odt_relation'] = true;

/**
 * @global boolean $cfg['Export']['odt_comments']
 */
$cfg['Export']['odt_comments'] = true;

/**
 * @global boolean $cfg['Export']['odt_mime']
 */
$cfg['Export']['odt_mime'] = true;

/**
 * @global string $cfg['Export']['odt_null']
 */
$cfg['Export']['odt_null'] = 'NULL';

/**
 * @global boolean $cfg['Export']['htmlword_structure_or_data']
 */
$cfg['Export']['htmlword_structure_or_data'] = 'structure_and_data';

/**
 * @global boolean $cfg['Export']['htmlword_columns']
 */
$cfg['Export']['htmlword_columns'] = false;

/**
 * @global string $cfg['Export']['htmlword_null']
 */
$cfg['Export']['htmlword_null'] = 'NULL';

/**
 * @global string $cfg['Export']['texytext_structure_or_data']
 */
$cfg['Export']['texytext_structure_or_data'] = 'structure_and_data';

/**
 * @global boolean $cfg['Export']['texytext_columns']
 */
$cfg['Export']['texytext_columns'] = false;

/**
 * @global string $cfg['Export']['texytext_null']
 */
$cfg['Export']['texytext_null'] = 'NULL';

/**
 * @global boolean $cfg['Export']['csv_columns']
 */
$cfg['Export']['csv_columns'] = false;

/**
 * @global string $cfg['Export']['csv_structure_or_data']
 */
$cfg['Export']['csv_structure_or_data'] = 'data';

/**
 * @global string $cfg['Export']['csv_null']
 */
$cfg['Export']['csv_null'] = 'NULL';

/**
 * @global string $cfg['Export']['csv_separator']
 */
$cfg['Export']['csv_separator'] = ',';

/**
 * @global string $cfg['Export']['csv_enclosed']
 */
$cfg['Export']['csv_enclosed'] = '"';

/**
 * @global string $cfg['Export']['csv_escaped']
 */
$cfg['Export']['csv_escaped'] = '"';

/**
 * @global string $cfg['Export']['csv_terminated']
 */
$cfg['Export']['csv_terminated'] = 'AUTO';

/**
 * @global string $cfg['Export']['csv_removeCRLF']
 */
$cfg['Export']['csv_removeCRLF'] = false;

/**
 * @global boolean $cfg['Export']['excel_columns']
 */
$cfg['Export']['excel_columns'] = true;

/**
 * @global string $cfg['Export']['excel_null']
 */
$cfg['Export']['excel_null'] = 'NULL';

/**
 * win/mac
 *
 * @global string $cfg['Export']['excel_edition']
 */
$cfg['Export']['excel_edition'] = 'win';

/**
 * @global string $cfg['Export']['excel_removeCRLF']
 */
$cfg['Export']['excel_removeCRLF'] = false;

/**
 * @global string $cfg['Export']['excel_structure_or_data']
 */
$cfg['Export']['excel_structure_or_data'] = 'data';

/**
 * @global string $cfg['Export']['latex_structure_or_data']
 */
$cfg['Export']['latex_structure_or_data'] = 'structure_and_data';

/**
 * @global boolean $cfg['Export']['latex_columns']
 */
$cfg['Export']['latex_columns'] = true;

/**
 * @global boolean $cfg['Export']['latex_relation']
 */
$cfg['Export']['latex_relation'] = true;

/**
 * @global boolean $cfg['Export']['latex_comments']
 */
$cfg['Export']['latex_comments'] = true;

/**
 * @global boolean $cfg['Export']['latex_mime']
 */
$cfg['Export']['latex_mime'] = true;

/**
 * @global string $cfg['Export']['latex_null']
 */
$cfg['Export']['latex_null'] = '\textit{NULL}';

/**
 * @global boolean $cfg['Export']['latex_caption']
 */
$cfg['Export']['latex_caption'] = true;

/**
 * @global string $cfg['Export']['latex_structure_caption']
 */
$cfg['Export']['latex_structure_caption'] = 'strLatexStructure';

/**
 * @global string $cfg['Export']['latex_structure_continued_caption']
 */
$cfg['Export']['latex_structure_continued_caption'] = 'strLatexStructure strLatexContinued';

/**
 * @global string $cfg['Export']['latex_data_caption']
 */
$cfg['Export']['latex_data_caption'] = 'strLatexContent';

/**
 * @global string $cfg['Export']['latex_data_continued_caption']
 */
$cfg['Export']['latex_data_continued_caption'] = 'strLatexContent strLatexContinued';

/**
 * @global string $cfg['Export']['latex_data_label']
 */
$cfg['Export']['latex_data_label'] = 'tab:@TABLE@-data';

/**
 * @global string $cfg['Export']['latex_structure_label']
 */
$cfg['Export']['latex_structure_label'] = 'tab:@TABLE@-structure';

/**
 * @global string $cfg['Export']['mediawiki_structure_or_data']
 */
$cfg['Export']['mediawiki_structure_or_data'] = 'data';

/**
 * @global boolean $cfg['Export']['mediawiki_caption']
 */
$cfg['Export']['mediawiki_caption'] = true;

/**
 * @global boolean $cfg['Export']['mediawiki_headers']
 */
$cfg['Export']['mediawiki_headers'] = true;

/**
 * @global string $cfg['Export']['ods_structure_or_data']
 */
$cfg['Export']['ods_structure_or_data'] = 'data';

/**
 * @global string $cfg['Export']['pdf_structure_or_data']
 */
$cfg['Export']['pdf_structure_or_data'] = 'data';

/**
 * @global string $cfg['Export']['phparray_structure_or_data']
 */
$cfg['Export']['phparray_structure_or_data'] = 'data';

/**
 * @global string $cfg['Export']['json_structure_or_data']
 */
$cfg['Export']['json_structure_or_data'] = 'data';

/**
 * Export functions
 *
 * @global string $cfg['Export']['json_pretty_print']
 */
$cfg['Export']['json_pretty_print'] = false;

/**
 * Export functions
 *
 * @global string $cfg['Export']['json_unicode']
 */
$cfg['Export']['json_unicode'] = true;

/**
 * @global string $cfg['Export']['remove_definer_from_definitions']
 */
$cfg['Export']['remove_definer_from_definitions'] = false;

/**
 * @global string $cfg['Export']['sql_structure_or_data']
 */
$cfg['Export']['sql_structure_or_data'] = 'structure_and_data';

/**
 * @global string $cfg['Export']['sql_compatibility']
 */
$cfg['Export']['sql_compatibility'] = 'NONE';

/**
 * Whether to include comments in SQL export.
 *
 * @global string $cfg['Export']['sql_include_comments']
 */
$cfg['Export']['sql_include_comments'] = true;

/**
 * @global boolean $cfg['Export']['sql_disable_fk']
 */
$cfg['Export']['sql_disable_fk'] = false;

/**
 * @global boolean $cfg['Export']['sql_views_as_tables']
 */
$cfg['Export']['sql_views_as_tables'] = false;

/**
 * @global boolean $cfg['Export']['sql_metadata']
 */
$cfg['Export']['sql_metadata'] = false;

/**
 * @global boolean $cfg['Export']['sql_use_transaction']
 */
$cfg['Export']['sql_use_transaction'] = true;

/**
 * @global boolean $cfg['Export']['sql_create_database']
 */
$cfg['Export']['sql_create_database'] = false;

/**
 * @global boolean $cfg['Export']['sql_drop_database']
 */
$cfg['Export']['sql_drop_database'] = false;

/**
 * @global boolean $cfg['Export']['sql_drop_table']
 */
$cfg['Export']['sql_drop_table'] = false;

/**
 * true by default for correct behavior when dealing with exporting
 * of VIEWs and the stand-in table
 *
 * @global boolean $cfg['Export']['sql_if_not_exists']
 */
$cfg['Export']['sql_if_not_exists'] = false;

/**
 * @global boolean $cfg['Export']['sql_view_current_user']
 */
$cfg['Export']['sql_view_current_user'] = false;

/**
 * @global boolean $cfg['Export']['sql_or_replace']
 */
$cfg['Export']['sql_or_replace_view'] = false;

/**
 * @global boolean $cfg['Export']['sql_procedure_function']
 */
$cfg['Export']['sql_procedure_function'] = true;

/**
 * @global boolean $cfg['Export']['sql_create_table']
 */
$cfg['Export']['sql_create_table'] = true;

/**
 * @global boolean $cfg['Export']['sql_create_view']
 */
$cfg['Export']['sql_create_view'] = true;

/**
 * @global boolean $cfg['Export']['sql_create_trigger']
 */
$cfg['Export']['sql_create_trigger'] = true;

/**
 * @global boolean $cfg['Export']['sql_auto_increment']
 */
$cfg['Export']['sql_auto_increment'] = true;

/**
 * @global boolean $cfg['Export']['sql_backquotes']
 */
$cfg['Export']['sql_backquotes'] = true;

/**
 * @global boolean $cfg['Export']['sql_dates']
 */
$cfg['Export']['sql_dates'] = false;

/**
 * @global boolean $cfg['Export']['sql_relation']
 */
$cfg['Export']['sql_relation'] = false;

/**
 * @global boolean $cfg['Export']['sql_truncate']
 */
$cfg['Export']['sql_truncate'] = false;

/**
 * @global boolean $cfg['Export']['sql_delayed']
 */
$cfg['Export']['sql_delayed'] = false;

/**
 * @global boolean $cfg['Export']['sql_ignore']
 */
$cfg['Export']['sql_ignore'] = false;

/**
 * Export time in UTC.
 *
 * @global boolean $cfg['Export']['sql_utc_time']
 */
$cfg['Export']['sql_utc_time'] = true;

/**
 * @global boolean $cfg['Export']['sql_hex_for_binary']
 */
$cfg['Export']['sql_hex_for_binary'] = true;

/**
 * insert/update/replace
 *
 * @global string $cfg['Export']['sql_type']
 */
$cfg['Export']['sql_type'] = 'INSERT';

/**
 * @global integer $cfg['Export']['sql_max_query_size']
 */
$cfg['Export']['sql_max_query_size'] = 50000;

/**
 * @global boolean $cfg['Export']['sql_mime']
 */
$cfg['Export']['sql_mime'] = false;

/**
 * \n is replaced by new line
 *
 * @global string $cfg['Export']['sql_header_comment']
 */
$cfg['Export']['sql_header_comment'] = '';

/**
 * Whether to use complete inserts, extended inserts, both, or neither
 *
 * @global string $cfg['Export']['sql_insert_syntax']
 */
$cfg['Export']['sql_insert_syntax'] = 'both';

/**
 * @global string $cfg['Export']['pdf_report_title']
 */
$cfg['Export']['pdf_report_title'] = '';

/**
 * @global string $cfg['Export']['xml_structure_or_data']
 */
$cfg['Export']['xml_structure_or_data'] = 'data';

/**
 * Export schema for each structure
 *
 * @global string $cfg['Export']['xml_export_struc']
 */
$cfg['Export']['xml_export_struc'] = true;

/**
 * Export events
 *
 * @global string $cfg['Export']['xml_export_events']
 */
$cfg['Export']['xml_export_events'] = true;

/**
 * Export functions
 *
 * @global string $cfg['Export']['xml_export_functions']
 */
$cfg['Export']['xml_export_functions'] = true;

/**
 * Export procedures
 *
 * @global string $cfg['Export']['xml_export_procedures']
 */
$cfg['Export']['xml_export_procedures'] = true;

/**
 * Export schema for each table
 *
 * @global string $cfg['Export']['xml_export_tables']
 */
$cfg['Export']['xml_export_tables'] = true;

/**
 * Export triggers
 *
 * @global string $cfg['Export']['xml_export_triggers']
 */
$cfg['Export']['xml_export_triggers'] = true;

/**
 * Export views
 *
 * @global string $cfg['Export']['xml_export_views']
 */
$cfg['Export']['xml_export_views'] = true;

/**
 * Export contents data
 *
 * @global string $cfg['Export']['xml_export_contents']
 */
$cfg['Export']['xml_export_contents'] = true;

/**
 * @global string $cfg['Export']['yaml_structure_or_data']
 */
$cfg['Export']['yaml_structure_or_data'] = 'data';

/*******************************************************************************
 * Import defaults
 */
$cfg['Import'] = [];

/**
 * @global string $cfg['Import']['format']
 */
$cfg['Import']['format'] = 'sql';

/**
 * Default charset for import.
 *
 * @global string $cfg['Import']['charset']
 */
$cfg['Import']['charset'] = '';

/**
 * @global boolean $cfg['Import']['allow_interrupt']
 */
$cfg['Import']['allow_interrupt'] = true;

/**
 * @global integer $cfg['Import']['skip_queries']
 */
$cfg['Import']['skip_queries'] = 0;

/**
 * @global string $cfg['Import']['sql_compatibility']
 */
$cfg['Import']['sql_compatibility'] = 'NONE';

/**
 * @global string $cfg['Import']['sql_no_auto_value_on_zero']
 */
$cfg['Import']['sql_no_auto_value_on_zero'] = true;

/**
 * @global string $cfg['Import']['sql_read_as_multibytes']
 */
$cfg['Import']['sql_read_as_multibytes'] = false;

/**
 * @global boolean $cfg['Import']['csv_replace']
 */
$cfg['Import']['csv_replace'] = false;

/**
 * @global boolean $cfg['Import']['csv_ignore']
 */
$cfg['Import']['csv_ignore'] = false;

/**
 * @global string $cfg['Import']['csv_terminated']
 */
$cfg['Import']['csv_terminated'] = ',';

/**
 * @global string $cfg['Import']['csv_enclosed']
 */
$cfg['Import']['csv_enclosed'] = '"';

/**
 * @global string $cfg['Import']['csv_escaped']
 */
$cfg['Import']['csv_escaped'] = '"';

/**
 * @global string $cfg['Import']['csv_new_line']
 */
$cfg['Import']['csv_new_line'] = 'auto';

/**
 * @global string $cfg['Import']['csv_columns']
 */
$cfg['Import']['csv_columns'] = '';

/**
 * @global string $cfg['Import']['csv_col_names']
 */
$cfg['Import']['csv_col_names'] = false;

/**
 * @global boolean $cfg['Import']['ldi_replace']
 */
$cfg['Import']['ldi_replace'] = false;

/**
 * @global boolean $cfg['Import']['ldi_ignore']
 */
$cfg['Import']['ldi_ignore'] = false;

/**
 * @global string $cfg['Import']['ldi_terminated']
 */
$cfg['Import']['ldi_terminated'] = ';';

/**
 * @global string $cfg['Import']['ldi_enclosed']
 */
$cfg['Import']['ldi_enclosed'] = '"';

/**
 * @global string $cfg['Import']['ldi_escaped']
 */
$cfg['Import']['ldi_escaped'] = '\\';

/**
 * @global string $cfg['Import']['ldi_new_line']
 */
$cfg['Import']['ldi_new_line'] = 'auto';

/**
 * @global string $cfg['Import']['ldi_columns']
 */
$cfg['Import']['ldi_columns'] = '';

/**
 * 'auto' for auto-detection, true or false for forcing
 *
 * @global string $cfg['Import']['ldi_local_option']
 */
$cfg['Import']['ldi_local_option'] = 'auto';

/**
 * @global string $cfg['Import']['ods_col_names']
 */
$cfg['Import']['ods_col_names'] = false;

/**
 * @global string $cfg['Import']['ods_empty_rows']
 */
$cfg['Import']['ods_empty_rows'] = true;

/**
 * @global string $cfg['Import']['ods_recognize_percentages']
 */
$cfg['Import']['ods_recognize_percentages'] = true;

/**
 * @global string $cfg['Import']['ods_recognize_currency']
 */
$cfg['Import']['ods_recognize_currency'] = true;

/*******************************************************************************
 * Schema export defaults
*/
$cfg['Schema'] = [];

/**
 * pdf/eps/dia/svg
 *
 * @global string $cfg['Schema']['format']
*/
$cfg['Schema']['format'] = 'pdf';

/**
 * @global string $cfg['Schema']['pdf_show_color']
 */
$cfg['Schema']['pdf_show_color'] = true;

/**
 * @global string $cfg['Schema']['pdf_show_keys']
 */
$cfg['Schema']['pdf_show_keys'] = false;

/**
 * @global string $cfg['Schema']['pdf_all_tables_same_width']
 */
$cfg['Schema']['pdf_all_tables_same_width'] = false;

/**
 * L/P
 *
 * @global string $cfg['Schema']['pdf_orientation']
 */
$cfg['Schema']['pdf_orientation'] = 'L';

/**
 * @global string $cfg['Schema']['pdf_paper']
 */
$cfg['Schema']['pdf_paper'] = 'A4';

/**
 * @global string $cfg['Schema']['pdf_show_grid']
 */
$cfg['Schema']['pdf_show_grid'] = false;

/**
 * @global string $cfg['Schema']['pdf_with_doc']
 */
$cfg['Schema']['pdf_with_doc'] = true;

/**
 * @global string $cfg['Schema']['pdf_table_order']
 */
$cfg['Schema']['pdf_table_order'] = '';

/**
 * @global string $cfg['Schema']['dia_show_color']
 */
$cfg['Schema']['dia_show_color'] = true;

/**
 * @global string $cfg['Schema']['dia_show_keys']
 */
$cfg['Schema']['dia_show_keys'] = false;

/**
 * L/P
 *
 * @global string $cfg['Schema']['dia_orientation']
 */
$cfg['Schema']['dia_orientation'] = 'L';

/**
 * @global string $cfg['Schema']['dia_paper']
 */
$cfg['Schema']['dia_paper'] = 'A4';

/**
 * @global string $cfg['Schema']['eps_show_color']
 */
$cfg['Schema']['eps_show_color'] = true;

/**
 * @global string $cfg['Schema']['eps_show_keys']
 */
$cfg['Schema']['eps_show_keys'] = false;

/**
 * @global string $cfg['Schema']['eps_all_tables_same_width']
 */
$cfg['Schema']['eps_all_tables_same_width'] = false;

/**
 * L/P
 *
 * @global string $cfg['Schema']['eps_orientation']
 */
$cfg['Schema']['eps_orientation'] = 'L';

/**
 * @global string $cfg['Schema']['svg_show_color']
 */
$cfg['Schema']['svg_show_color'] = true;

/**
 * @global string $cfg['Schema']['svg_show_keys']
 */
$cfg['Schema']['svg_show_keys'] = false;

/**
 * @global string $cfg['Schema']['svg_all_tables_same_width']
 */
$cfg['Schema']['svg_all_tables_same_width'] = false;

/*******************************************************************************
 * PDF options
 */

/**
 * @global array $cfg['PDFPageSizes']
 */
$cfg['PDFPageSizes'] = [
    'A3',
    'A4',
    'A5',
    'letter',
    'legal',
];

/**
 * @global string $cfg['PDFDefaultPageSize']
 */
$cfg['PDFDefaultPageSize'] = 'A4';


/*******************************************************************************
 * Language and character set conversion settings
 */

/**
 * Default language to use, if not browser-defined or user-defined
 *
 * @global string $cfg['DefaultLang']
 */
$cfg['DefaultLang'] = 'en';

/**
 * Default connection collation
 *
 * @global string $cfg['DefaultConnectionCollation']
 */
$cfg['DefaultConnectionCollation'] = 'utf8mb4_unicode_ci';

/**
 * Force: always use this language, e.g. 'en'
 *
 * @global string $cfg['Lang']
 */
$cfg['Lang'] = '';

/**
 * Regular expression to limit listed languages, e.g. '^(cs|en)' for Czech and
 * English only
 *
 * @global string $cfg['FilterLanguages']
 */
$cfg['FilterLanguages'] = '';

/**
 * You can select here which functions will be used for character set conversion.
 * Possible values are:
 *      auto   - automatically use available one (first is tested iconv, then
 *               recode)
 *      iconv  - use iconv or libiconv functions
 *      recode - use recode_string function
 *      mb     - use mbstring extension
 *      none   - disable encoding conversion
 *
 * @global string $cfg['RecodingEngine']
 */
$cfg['RecodingEngine'] = 'auto';

/**
 * Specify some parameters for iconv used in character set conversion. See iconv
 * documentation for details:
 * https://www.gnu.org/savannah-checkouts/gnu/libiconv/documentation/libiconv-1.15/iconv_open.3.html
 *
 * @global string $cfg['IconvExtraParams']
 */
$cfg['IconvExtraParams'] = '//TRANSLIT';

/**
 * Available character sets for MySQL conversion. currently contains all which could
 * be found in lang/* files and few more.
 * Character sets will be shown in same order as here listed, so if you frequently
 * use some of these move them to the top.
 *
 * @global array $cfg['AvailableCharsets']
 */
$cfg['AvailableCharsets'] = [
    'iso-8859-1',
    'iso-8859-2',
    'iso-8859-3',
    'iso-8859-4',
    'iso-8859-5',
    'iso-8859-6',
    'iso-8859-7',
    'iso-8859-8',
    'iso-8859-9',
    'iso-8859-10',
    'iso-8859-11',
    'iso-8859-12',
    'iso-8859-13',
    'iso-8859-14',
    'iso-8859-15',
    'windows-1250',
    'windows-1251',
    'windows-1252',
    'windows-1256',
    'windows-1257',
    'koi8-r',
    'big5',
    'gb2312',
    'utf-16',
    'utf-8',
    'utf-7',
    'x-user-defined',
    'euc-jp',
    'ks_c_5601-1987',
    'tis-620',
    'SHIFT_JIS',
    'SJIS',
    'SJIS-win',
];


/*******************************************************************************
 * Customization & design
 *
 * The graphical settings are now located in themes/theme-name/scss/_variables.scss
 */

/**
 * enable the left panel pointer
 *
 * @global boolean $cfg['NavigationTreePointerEnable']
 */
$cfg['NavigationTreePointerEnable'] = true;

/**
 * enable the browse pointer
 *
 * @global boolean $cfg['BrowsePointerEnable']
 */
$cfg['BrowsePointerEnable'] = true;

/**
 * enable the browse marker
 *
 * @global boolean $cfg['BrowseMarkerEnable']
 */
$cfg['BrowseMarkerEnable'] = true;

/**
 * textarea size (columns) in edit mode
 * (this value will be emphasized (*2) for SQL
 * query textareas and (*1.25) for query window)
 *
 * @global integer $cfg['TextareaCols']
 */
$cfg['TextareaCols'] = 40;

/**
 * textarea size (rows) in edit mode
 *
 * @global integer $cfg['TextareaRows']
 */
$cfg['TextareaRows'] = 15;

/**
 * double size of textarea size for LONGTEXT columns
 *
 * @global boolean $cfg['LongtextDoubleTextarea']
 */
$cfg['LongtextDoubleTextarea'] = true;

/**
 * auto-select when clicking in the textarea of the query-box
 *
 * @global boolean $cfg['TextareaAutoSelect']
 */
$cfg['TextareaAutoSelect'] = false;

/**
 * textarea size (columns) for CHAR/VARCHAR
 *
 * @global integer $cfg['CharTextareaCols']
 */
$cfg['CharTextareaCols'] = 40;

/**
 * textarea size (rows) for CHAR/VARCHAR
 *
 * @global integer $cfg['CharTextareaRows']
 */
$cfg['CharTextareaRows'] = 7;

/**
 * Max field data length in browse mode for all non-numeric fields
 *
 * @global integer $cfg['LimitChars']
 */
$cfg['LimitChars'] = 50;

/**
 * Where to show the edit/copy/delete links in browse mode
 * Possible values are 'left', 'right', 'both' and 'none'.
 *
 * @global string $cfg['RowActionLinks']
 */
$cfg['RowActionLinks'] = 'left';

/**
 * Whether to show row links (Edit, Copy, Delete) and checkboxes for
 * multiple row operations even when the selection does not have a unique key.
 *
 * @global boolean $cfg['RowActionLinksWithoutUnique']
 */
$cfg['RowActionLinksWithoutUnique'] = false;

/**
 * Default sort order by primary key.
 *
 * @global string $cfg['TablePrimaryKeyOrder']
 */
$cfg['TablePrimaryKeyOrder'] = 'NONE';

/**
 * remember the last way a table sorted
 *
 * @global string $cfg['RememberSorting']
 */
$cfg['RememberSorting'] = true;

/**
 * shows column comments in 'browse' mode.
 *
 * @global boolean $cfg['ShowBrowseComments']
 */
$cfg['ShowBrowseComments'] = true;

/**
 * shows column comments in 'table property' mode.
 *
 * @global boolean $cfg['ShowPropertyComments']
 */
$cfg['ShowPropertyComments'] = true;

/**
 * repeat header names every X cells? (0 = deactivate)
 *
 * @global integer $cfg['RepeatCells']
 */
$cfg['RepeatCells'] = 100;

/**
 * Set to true if you want DB-based query history.If false, this utilizes
 * JS-routines to display query history (lost by window close)
 *
 * @global boolean $cfg['QueryHistoryDB']
 */
$cfg['QueryHistoryDB'] = false;

/**
 * When using DB-based query history, how many entries should be kept?
 *
 * @global integer $cfg['QueryHistoryMax']
 */
$cfg['QueryHistoryMax'] = 25;

/**
 * Use MIME-Types (stored in column comments table) for
 *
 * @global boolean $cfg['BrowseMIME']
 */
$cfg['BrowseMIME'] = true;

/**
 * When approximate count < this, PMA will get exact count for table rows.
 *
 * @global integer $cfg['MaxExactCount']
 */
$cfg['MaxExactCount'] = 50000;

/**
 * Zero means that no row count is done for views; see the doc
 *
 * @global integer $cfg['MaxExactCountViews']
 */
$cfg['MaxExactCountViews'] = 0;

/**
 * Sort table and database in natural order
 *
 * @global boolean $cfg['NaturalOrder']
 */
$cfg['NaturalOrder'] = true;

/**
 * Initial state for sliders
 * (open | closed | disabled)
 *
 * @global string $cfg['InitialSlidersState']
 */
$cfg['InitialSlidersState'] = 'closed';

/**
 * User preferences: disallow these settings
 * For possible setting names look in libraries/config/user_preferences.forms.php
 *
 * @global array $cfg['UserprefsDisallow']
 */
$cfg['UserprefsDisallow'] = [];

/**
 * User preferences: enable the Developer tab
 */
$cfg['UserprefsDeveloperTab'] = false;

/*******************************************************************************
 * Window title settings
 */

/**
 * title of browser window when a table is selected
 *
 * @global string $cfg['TitleTable']
 */
$cfg['TitleTable'] = '@HTTP_HOST@ / @VSERVER@ / @DATABASE@ / @TABLE@ | @PHPMYADMIN@';

/**
 * title of browser window when a database is selected
 *
 * @global string $cfg['TitleDatabase']
 */
$cfg['TitleDatabase'] = '@HTTP_HOST@ / @VSERVER@ / @DATABASE@ | @PHPMYADMIN@';

/**
 * title of browser window when a server is selected
 *
 * @global string $cfg['TitleServer']
 */
$cfg['TitleServer'] = '@HTTP_HOST@ / @VSERVER@ | @PHPMYADMIN@';

/**
 * title of browser window when nothing is selected
 *
 * @global string $cfg['TitleDefault']
 */
$cfg['TitleDefault'] = '@HTTP_HOST@ | @PHPMYADMIN@';


/*******************************************************************************
 * theme manager
 */

/**
 * if you want to use selectable themes and if ThemesPath not empty
 * set it to true, else set it to false (default is false);
 *
 * @global boolean $cfg['ThemeManager']
 */
$cfg['ThemeManager'] = true;

/**
 * set up default theme, you can set up here an valid
 * path to themes or 'original' for the original pma-theme
 *
 * @global string $cfg['ThemeDefault']
 */
$cfg['ThemeDefault'] = 'pmahomme';

/**
 * allow different theme for each configured server
 *
 * @global boolean $cfg['ThemePerServer']
 */
$cfg['ThemePerServer'] = false;


/**
 * Default query for table
 *
 * @global string $cfg['DefaultQueryTable']
 */
$cfg['DefaultQueryTable'] = 'SELECT * FROM @TABLE@ WHERE 1';

/**
 * Default query for database
 *
 * @global string $cfg['DefaultQueryDatabase']
 */
$cfg['DefaultQueryDatabase'] = '';


/*******************************************************************************
 * SQL Query box settings
 * These are the links display in all of the SQL Query boxes
 *
 * @global array $cfg['SQLQuery']
 */
$cfg['SQLQuery'] = [];

/**
 * Display an "Edit" link on the results page to change a query
 *
 * @global boolean $cfg['SQLQuery']['Edit']
 */
$cfg['SQLQuery']['Edit'] = true;

/**
 * Display an "Explain SQL" link on the results page
 *
 * @global boolean $cfg['SQLQuery']['Explain']
 */
$cfg['SQLQuery']['Explain'] = true;

/**
 * Display a "Create PHP code" link on the results page to wrap a query in PHP
 *
 * @global boolean $cfg['SQLQuery']['ShowAsPHP']
 */
$cfg['SQLQuery']['ShowAsPHP'] = true;

/**
 * Display a "Refresh" link on the results page
 *
 * @global boolean $cfg['SQLQuery']['Refresh']
 */
$cfg['SQLQuery']['Refresh'] = true;

/**
 * Enables autoComplete for table & column names in SQL queries
 *
 * default = 'true'
 */
$cfg['EnableAutocompleteForTablesAndColumns'] = true;


/*******************************************************************************
 * Web server upload/save/import directories
 */

/**
 * Directory for uploaded files that can be executed by phpMyAdmin.
 * For example './upload'. Leave empt